<?php

declare (strict_types=1);
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Symfony\Flex\Command;

use Composer\Command\Base_Command;
use Composer\Dependency_Resolver\Operation\Install_Operation;
use Composer\Util\Process_Executor;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\Input_Argument;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Input\Input_Option;
use Symfony\Component\Console\Output\Output_Interface;
use Symfony\Flex\Event\Update_Event;
use Symfony\Flex\Flex;
class Install_Recipes_Command extends Base_Command
{
    /**
     * @param Flex $flex
     */
    public function __construct(
        /* cannot be type-hinted */
        private $flex,
        private readonly string $root_dir,
        private readonly string $dotenv_path = '.env'
    )
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->set_name('symfony:recipes:install')->set_aliases(['recipes:install', 'symfony:sync-recipes', 'sync-recipes', 'fix-recipes'])->set_description('Installs or reinstalls recipes for already installed packages.')->add_argument('packages', Input_Argument::IS_ARRAY | Input_Argument::OPTIONAL, 'Recipes that should be installed.')->add_option('force', null, Input_Option::VALUE_NONE, 'Overwrite existing files when a new version of a recipe is available')->add_option('reset', null, Input_Option::VALUE_NONE, 'Reset all recipes back to their initial state (should be combined with --force)')->add_option('yes', null, Input_Option::VALUE_NONE, "Answer prompt questions with 'yes' for all questions.");
    }
    protected function execute(Input_Interface $input, Output_Interface $output): int
    {
        $win = '\\' === \DIRECTORY_SEPARATOR;
        $force = (bool) $input->get_option('force');
        if ($force && !@is_executable(strtok(exec($win ? 'where git' : 'command -v git'), \PHP_EOL))) {
            throw new RuntimeException('Cannot run "sync-recipes --force": git not found.');
        }
        $symfony_lock = $this->flex->get_lock();
        $composer = $this->get_composer();
        $locker = $composer->get_locker();
        $lock_data = $locker->get_lock_data();
        $packages = [];
        $total_packages = [];
        foreach ($lock_data['packages'] as $pkg) {
            $total_packages[] = $pkg['name'];
            if ($force || !$symfony_lock->has($pkg['name'])) {
                $packages[] = $pkg['name'];
            }
        }
        foreach ($lock_data['packages-dev'] as $pkg) {
            $total_packages[] = $pkg['name'];
            if ($force || !$symfony_lock->has($pkg['name'])) {
                $packages[] = $pkg['name'];
            }
        }
        $io = $this->get_io();
        if (!$io->is_verbose()) {
            $io->write_error(['Run command with <info>-v</info> to see more details', '']);
        }
        if ($target_packages = $input->get_argument('packages')) {
            if ($invalid_packages = array_diff($target_packages, $total_packages)) {
                $io->write_error(\sprintf('<warning>Cannot update: some packages are not installed:</warning> %s', implode(', ', $invalid_packages)));
                return 1;
            }
            if ($packages_requiring_force = array_diff($target_packages, $packages)) {
                $io->write_error(\sprintf('Recipe(s) already installed for: <info>%s</info>', implode(', ', $packages_requiring_force)));
                $io->write_error('Re-run the command with <info>--force</info> to re-install the recipes.');
                $io->write_error('');
            }
            $packages = array_diff($target_packages, $packages_requiring_force);
        }
        if (!$packages) {
            $io->write_error('No recipes to install.');
            return 0;
        }
        $composer = $this->get_composer();
        $installed_repo = $composer->get_repository_manager()->get_local_repository();
        $operations = [];
        foreach ($packages as $package) {
            if (null === $pkg = $installed_repo->find_package($package, '*')) {
                $io->write_error(\sprintf('<error>Package %s is not installed</>', $package));
                return 1;
            }
            $operations[] = new Install_Operation($pkg);
        }
        $dotenv_file = $this->dotenv_path;
        $dotenv_path = $this->root_dir . '/' . $dotenv_file;
        if ($create_env_local = $force && file_exists($dotenv_path) && file_exists($dotenv_path . '.dist') && !file_exists($dotenv_path . '.local')) {
            rename($dotenv_path, $dotenv_path . '.local');
            $pipes = [];
            proc_close(proc_open(\sprintf('git mv %s %s > %s 2>&1 || %s %1$s %2$s', Process_Executor::escape($dotenv_file . '.dist'), Process_Executor::escape($dotenv_file), $win ? 'NUL' : '/dev/null', $win ? 'rename' : 'mv'), $pipes, $pipes, $this->root_dir));
            if (file_exists($this->root_dir . '/phpunit.xml.dist') || file_exists($this->root_dir . '/phpunit.dist.xml')) {
                touch($dotenv_path . '.test');
            }
        }
        $this->flex->update(new Update_Event($force, (bool) $input->get_option('reset'), (bool) $input->get_option('yes')), $operations);
        if ($force) {
            $output = ['', '<bg=blue;fg=white>                                                            </>', '<bg=blue;fg=white> Files have been reset to the latest version of the recipe. </>', '<bg=blue;fg=white>                                                            </>', '', '  * Use <comment>git diff</> to inspect the changes.', '', '    Not all of the changes will be relevant to your app: you now', '    need to selectively add or revert them using e.g. a combination', '    of <comment>git add -p</> and <comment>git checkout -p</>', ''];
            if ($create_env_local) {
                $output[] = '    Dotenv files have been renamed: .env -> .env.local and .env.dist -> .env';
                $output[] = '    See https://symfony.com/doc/current/configuration/dot-env-changes.html';
                $output[] = '';
            }
            $output[] = '  * Use <comment>git checkout .</> to revert the changes.';
            $output[] = '';
            if ($create_env_local) {
                $root = '.' !== $this->root_dir ? $this->root_dir . '/' : '';
                $output[] = '    To revert the changes made to .env files, run';
                $output[] = \sprintf('    <comment>git mv %s %s</> && <comment>%s %s %1$s</>', Process_Executor::escape($root . $dotenv_file), Process_Executor::escape($root . $dotenv_file . '.dist'), $win ? 'rename' : 'mv', Process_Executor::escape($root . $dotenv_file . '.local'));
                $output[] = '';
            }
            $output[] = '    New (untracked) files can be inspected using <comment>git clean --dry-run</>';
            $output[] = '    Add the new files you want to keep using <comment>git add</>';
            $output[] = '    then delete the rest using <comment>git clean --force</>';
            $output[] = '';
            $io->write($output);
        }
        return 0;
    }
}