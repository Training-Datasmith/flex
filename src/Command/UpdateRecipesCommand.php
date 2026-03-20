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
use Composer\IO\Io_Interface;
use Composer\Package\Package;
use Composer\Package\Package_Interface;
use Composer\Util\Process_Executor;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\Input_Argument;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Output\Output_Interface;
use Symfony\Flex\Configurator;
use Symfony\Flex\Downloader;
use Symfony\Flex\Flex;
use Symfony\Flex\Github_Api;
use Symfony\Flex\Information_Operation;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Patcher;
use Symfony\Flex\Update\Recipe_Update;
class Update_Recipes_Command extends Base_Command
{
    private readonly \Symfony\Flex\Github_Api $github_api;
    private ?\Composer\Util\Process_Executor $process_executor = null;
    /**
     * @param Flex $flex
     */
    public function __construct(
        /* cannot be type-hinted */
        private $flex,
        private readonly Downloader $downloader,
        $http_downloader,
        private readonly Configurator $configurator,
        private readonly string $root_dir
    )
    {
        $this->github_api = new Github_Api($http_downloader);
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->set_name('symfony:recipes:update')->set_aliases(['recipes:update'])->set_description('Updates an already-installed recipe to the latest version.')->add_argument('package', Input_Argument::OPTIONAL, 'Recipe that should be updated.');
    }
    protected function execute(Input_Interface $input, Output_Interface $output): int
    {
        $win = '\\' === \DIRECTORY_SEPARATOR;
        $runtime_exception_class = class_exists(RuntimeException::class) ? RuntimeException::class : \RuntimeException::class;
        if (!@is_executable(strtok(exec($win ? 'where git' : 'command -v git'), \PHP_EOL))) {
            throw new $runtime_exception_class('Cannot run "recipes:update": git not found.');
        }
        $io = $this->get_io();
        if (!$this->is_index_clean()) {
            $io->write(['  Cannot run <comment>recipes:update</comment>: Your git index contains uncommitted changes.', '  Please commit or stash them and try again!']);
            return 1;
        }
        $package_name = $input->get_argument('package');
        $symfony_lock = $this->flex->get_lock();
        if (!$package_name) {
            $package_name = $this->ask_for_package($io, $symfony_lock);
            if (null === $package_name) {
                $io->write_error('All packages appear to be up-to-date!');
                return 0;
            }
        }
        if (!$symfony_lock->has($package_name)) {
            $io->write_error(['Package not found inside symfony.lock. It looks like it\'s not installed?', \sprintf('Try running <info>composer recipes:install %s --force -v</info> to re-install the recipe.', $package_name)]);
            return 1;
        }
        $package_lock_data = $symfony_lock->get($package_name);
        if (!isset($package_lock_data['recipe'])) {
            $io->write_error(['It doesn\'t look like this package had a recipe when it was originally installed.', 'To install the latest version of the recipe, if there is one, run:', \sprintf('  <info>composer recipes:install %s --force -v</info>', $package_name)]);
            return 1;
        }
        $recipe_ref = $package_lock_data['recipe']['ref'] ?? null;
        $recipe_version = $package_lock_data['recipe']['version'] ?? null;
        if (!$recipe_ref || !$recipe_version) {
            $io->write_error(['The version of the installed recipe was not saved into symfony.lock.', 'This is possible if it was installed by an old version of Symfony Flex.', 'Update the recipe by re-installing the latest version with:', \sprintf('  <info>composer recipes:install %s --force -v</info>', $package_name)]);
            return 1;
        }
        $installed_repo = $this->get_composer()->get_repository_manager()->get_local_repository();
        $package = $installed_repo->find_package($package_name, '*') ?? new Package($package_name, $package_lock_data['version'], $package_lock_data['version']);
        $original_recipe = $this->get_recipe($package, $recipe_ref, $recipe_version);
        if (null === $original_recipe) {
            $io->write_error(['The original recipe version you have installed could not be found, it may be too old.', 'Update the recipe by re-installing the latest version with:', \sprintf('  <info>composer recipes:install %s --force -v</info>', $package_name)]);
            return 1;
        }
        $new_recipe = $this->get_recipe($package);
        if ($new_recipe->get_ref() === $original_recipe->get_ref()) {
            $io->write(\sprintf('This recipe for <info>%s</info> is already at the latest version.', $package_name));
            return 0;
        }
        $io->write([\sprintf('  Updating recipe for <info>%s</info>...', $package_name), '']);
        $recipe_update = new Recipe_Update($original_recipe, $new_recipe, $symfony_lock, $this->root_dir);
        $this->configurator->populate_update($recipe_update);
        $original_composer_json_hash = $this->flex->get_composer_json_hash();
        $patcher = new Recipe_Patcher($this->root_dir, $io, $symfony_lock);
        try {
            $patch = $patcher->generate_patch($recipe_update->get_original_files(), $recipe_update->get_new_files());
            $has_conflicts = !$patcher->apply_patch($patch, $package_name);
        } catch (\Throwable $throwable) {
            $io->write_error(['<bg=red;fg=white>There was an error applying the recipe update patch</>', $throwable->get_message(), '', 'Update the recipe by re-installing the latest version with:', \sprintf('  <info>composer recipes:install %s --force -v</info>', $package_name)]);
            return 1;
        }
        $symfony_lock->add($package_name, $new_recipe->get_lock());
        $this->flex->finish($this->root_dir, $original_composer_json_hash);
        // stage symfony.lock, as all patched files with already be staged
        $cmd_output = '';
        $this->get_process_executor()->execute('git add symfony.lock', $cmd_output, $this->root_dir);
        $io->write(['  <bg=blue;fg=white>                      </>', '  <bg=blue;fg=white> Yes! Recipe updated! </>', '  <bg=blue;fg=white>                      </>', '']);
        if ($has_conflicts) {
            $io->write(['  The recipe was updated but with <bg=red;fg=white>one or more conflicts</>.', '  Run <comment>git status</comment> to see them.', '  After resolving, commit your changes like normal.']);
        } else if (!$patch->get_patch()) {
            // no changes were required
            $io->write(['  No files were changed as a result of the update.']);
        } else {
            $io->write(['  Run <comment>git status</comment> or <comment>git diff --cached</comment> to see the changes.', '  When you\'re ready, commit these changes like normal.']);
        }
        if (0 !== \count($recipe_update->get_copy_from_package_paths())) {
            $io->write(['', '  <bg=red;fg=white>NOTE:</>', '  This recipe copies the following paths from the bundle into your app:']);
            foreach ($recipe_update->get_copy_from_package_paths() as $source => $target) {
                $io->write(\sprintf('  * %s => %s', $source, $target));
            }
            $io->write(['', '  The recipe updater has no way of knowing if these files have changed since you originally installed the recipe.', '  And so, no updates were made to these paths.']);
        }
        if (0 !== \count($patch->get_removed_patches())) {
            if (1 === \count($patch->get_removed_patches())) {
                $notes = [\sprintf('  The file <comment>%s</comment> was not updated because it doesn\'t exist in your app.', array_keys($patch->get_removed_patches())[0])];
            } else {
                $notes = ['  The following files were not updated because they don\'t exist in your app:'];
                foreach ($patch->get_removed_patches() as $filename => $contents) {
                    $notes[] = \sprintf('    * <comment>%s</comment>', $filename);
                }
            }
            $io->write(['', '  <bg=red;fg=white>NOTE:</>']);
            $io->write($notes);
            $io->write('');
            if ($io->ask_confirmation('  Would you like to save the "diff" to a file so you can review it? (Y/n) ')) {
                $patch_filename = str_replace('/', '.', $package_name) . '.updates-for-deleted-files.patch';
                file_put_contents($this->root_dir . '/' . $patch_filename, implode("\n", $patch->get_removed_patches()));
                $io->write(['', \sprintf('  Saved diff to <info>%s</info>', $patch_filename)]);
            }
        }
        if ($patch->get_patch()) {
            $io->write('');
            $io->write('  Calculating CHANGELOG...', false);
            $changelog = $this->generate_changelog($original_recipe);
            $io->write("\r", false);
            // clear current line
            if ($changelog) {
                $io->write($changelog);
            } else {
                $io->write('No CHANGELOG could be calculated.');
            }
        }
        return 0;
    }
    private function get_recipe(Package_Interface $package, ?string $recipe_ref = null, ?string $recipe_version = null): ?Recipe
    {
        $operation = new Information_Operation($package);
        if (null !== $recipe_ref) {
            $operation->set_specific_recipe_version($recipe_ref, $recipe_version);
        }
        $recipes = $this->downloader->get_recipes([$operation]);
        if (0 === \count($recipes['manifests'] ?? [])) {
            return null;
        }
        return new Recipe($package, $package->get_name(), $operation->get_operation_type(), $recipes['manifests'][$package->get_name()], $recipes['locks'][$package->get_name()] ?? []);
    }
    private function generate_changelog(Recipe $original_recipe): ?array
    {
        $recipe_data = $original_recipe->get_lock()['recipe'] ?? null;
        if (null === $recipe_data) {
            return null;
        }
        if (!isset($recipe_data['ref']) || !isset($recipe_data['repo']) || !isset($recipe_data['branch']) || !isset($recipe_data['version'])) {
            return null;
        }
        $current_recipe_version_data = $this->github_api->find_recipe_commit_data_from_tree_ref($original_recipe->get_name(), $recipe_data['repo'], $recipe_data['branch'], $recipe_data['version'], $recipe_data['ref']);
        if (!$current_recipe_version_data) {
            return null;
        }
        $recipe_versions = $this->github_api->get_versions_of_recipe($recipe_data['repo'], $recipe_data['branch'], $original_recipe->get_name());
        if (!$recipe_versions) {
            return null;
        }
        $newer_recipe_versions = array_filter($recipe_versions, fn($version) => version_compare($version, $recipe_data['version'], '>'));
        $new_commits = $current_recipe_version_data['new_commits'];
        foreach ($newer_recipe_versions as $newer_recipe_version) {
            $new_commits = array_merge($new_commits, $this->github_api->get_commit_data_for_path($recipe_data['repo'], $original_recipe->get_name() . '/' . $newer_recipe_version, $recipe_data['branch']));
        }
        $new_commits = array_unique($new_commits);
        asort($new_commits);
        $pull_requests = [];
        foreach ($new_commits as $commit => $date) {
            $pr = $this->github_api->get_pull_request_for_commit($commit, $recipe_data['repo']);
            if ($pr) {
                $pull_requests[$pr['number']] = $pr;
            }
        }
        $lines = [];
        // borrowed from symfony/console's OutputFormatterStyle
        $handles_href_gracefully = 'JetBrains-JediTerm' !== getenv('TERMINAL_EMULATOR') && (!getenv('KONSOLE_VERSION') || (int) getenv('KONSOLE_VERSION') > 201100);
        foreach ($pull_requests as $number => $data) {
            $url = $data['url'];
            if ($handles_href_gracefully) {
                $url = "\x1b]8;;{$url}\x1b\\{$number}\x1b]8;;\x1b\\";
            }
            $lines[] = \sprintf('  * %s (PR %s)', $data['title'], $url);
        }
        return $lines;
    }
    private function ask_for_package(Io_Interface $io, Lock $symfony_lock): ?string
    {
        $installed_repo = $this->get_composer()->get_repository_manager()->get_local_repository();
        $operations = [];
        foreach ($symfony_lock->all() as $name => $lock) {
            if (isset($lock['recipe']['ref'])) {
                $package = $installed_repo->find_package($name, '*') ?? new Package($name, $lock['version'], $lock['version']);
                $operations[] = new Information_Operation($package);
            }
        }
        $recipes = $this->flex->fetch_recipes($operations, false);
        ksort($recipes);
        $outdated_recipes = [];
        foreach ($recipes as $name => $recipe) {
            $lock_ref = $symfony_lock->get($name)['recipe']['ref'] ?? null;
            if (null !== $lock_ref && $recipe->get_ref() !== $lock_ref && !$recipe->is_auto()) {
                $outdated_recipes[] = $name;
            }
        }
        if (0 === \count($outdated_recipes)) {
            return null;
        }
        $question = 'Which outdated recipe would you like to update? (default: <info>0</info>)';
        $choice = $io->select($question, $outdated_recipes, 0);
        return $outdated_recipes[$choice];
    }
    private function is_index_clean(): bool
    {
        $output = '';
        $this->get_process_executor()->execute('git status --porcelain --untracked-files=no', $output, $this->root_dir);
        if ('' !== trim($output)) {
            return false;
        }
        return true;
    }
    private function get_process_executor(): Process_Executor
    {
        if (null === $this->process_executor) {
            $this->process_executor = new Process_Executor($this->get_io());
        }
        return $this->process_executor;
    }
}