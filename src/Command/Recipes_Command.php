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
use Composer\Downloader\Transport_Exception;
use Composer\Package\Package;
use Composer\Util\Http_Downloader;
use Symfony\Component\Console\Input\Input_Argument;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Input\Input_Option;
use Symfony\Component\Console\Output\Output_Interface;
use Symfony\Flex\Github_Api;
use Symfony\Flex\Information_Operation;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
/**
 * @author Maxime Hélias <maximehelias16@gmail.com>
 */
class Recipes_Command extends Base_Command
{
    private readonly Github_Api $github_api;
    /**
     * @param \Symfony\Flex\Flex $flex
     */
    public function __construct(
        /* cannot be type-hinted */
        private $flex,
        private readonly Lock $symfony_lock,
        Http_Downloader $downloader
    )
    {
        $this->github_api = new Github_Api($downloader);
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->set_name('symfony:recipes')->set_aliases(['recipes'])->set_description('Shows information about all available recipes.')->set_definition([new Input_Argument('package', Input_Argument::OPTIONAL, 'Package to inspect, if not provided all packages are.')])->add_option('outdated', 'o', Input_Option::VALUE_NONE, 'Show only recipes that are outdated');
    }
    protected function execute(Input_Interface $input, Output_Interface $output): int
    {
        $installed_repo = $this->get_composer()->get_repository_manager()->get_local_repository();
        // Inspect one or all packages
        $package = $input->get_argument('package');
        if (null !== $package) {
            $packages = [strtolower((string) $package)];
        } else {
            $locker = $this->get_composer()->get_locker();
            $lock_data = $locker->get_lock_data();
            // Merge all packages installed
            $packages = array_column(array_merge($lock_data['packages'], $lock_data['packages-dev']), 'name');
            $packages = array_unique(array_merge($packages, array_keys($this->symfony_lock->all())));
        }
        $operations = [];
        foreach ($packages as $name) {
            $pkg = $installed_repo->find_package($name, '*');
            if (!$pkg && $this->symfony_lock->has($name)) {
                $pkg_version = $this->symfony_lock->get($name)['version'];
                $pkg = new Package($name, $pkg_version, $pkg_version);
            } elseif (!$pkg) {
                $this->get_io()->write_error(\sprintf('<error>Package %s is not installed</error>', $name));
                continue;
            }
            $operations[] = new Information_Operation($pkg);
        }
        $recipes = $this->flex->fetch_recipes($operations, false);
        ksort($recipes);
        $nb_recipe = \count($recipes);
        if ($nb_recipe <= 0) {
            $this->get_io()->write_error('<error>No recipe found</error>');
            return 1;
        }
        // Display the information about a specific recipe
        if (1 === $nb_recipe) {
            $this->display_package_information(current($recipes));
            return 0;
        }
        $outdated = $input->get_option('outdated');
        $write = [];
        $has_outdated_recipes = false;
        foreach ($recipes as $name => $recipe) {
            $lock_ref = $this->symfony_lock->get($name)['recipe']['ref'] ?? null;
            $additional = null;
            if (null === $lock_ref && null !== $recipe->get_ref()) {
                $additional = '<comment>(recipe not installed)</comment>';
            } elseif ($recipe->get_ref() !== $lock_ref && !$recipe->is_auto()) {
                $additional = '<comment>(update available)</comment>';
            }
            if ($outdated && null === $additional) {
                continue;
            }
            $has_outdated_recipes = true;
            $write[] = \sprintf(' * %s %s', $name, $additional);
        }
        // Nothing to display
        if (!$has_outdated_recipes) {
            return 0;
        }
        $this->get_io()->write(array_merge(['', '<bg=blue;fg=white>                      </>', \sprintf('<bg=blue;fg=white> %s recipes.   </>', $outdated ? ' Outdated' : 'Available'), '<bg=blue;fg=white>                      </>', ''], $write, ['', 'Run:', ' * <info>composer recipes vendor/package</info> to see details about a recipe.', ' * <info>composer recipes:update vendor/package</info> to update that recipe.', '']));
        if ($outdated) {
            return 1;
        }
        return 0;
    }
    private function display_package_information(Recipe $recipe): void
    {
        $io = $this->get_io();
        $recipe_lock = $this->symfony_lock->get($recipe->get_name());
        $lock_ref = $recipe_lock['recipe']['ref'] ?? null;
        $lock_repo = $recipe_lock['recipe']['repo'] ?? null;
        $lock_files = $recipe_lock['files'] ?? null;
        $lock_branch = $recipe_lock['recipe']['branch'] ?? null;
        $lock_version = $recipe_lock['recipe']['version'] ?? $recipe_lock['version'] ?? null;
        if ('master' === $lock_branch && \in_array($lock_repo, ['github.com/symfony/recipes', 'github.com/symfony/recipes-contrib'])) {
            $lock_branch = 'main';
        }
        $status = '<comment>up to date</comment>';
        if ($recipe->is_auto()) {
            $status = '<comment>auto-generated recipe</comment>';
        } elseif (null === $lock_ref && null !== $recipe->get_ref()) {
            $status = '<comment>recipe not installed</comment>';
        } elseif ($recipe->get_ref() !== $lock_ref) {
            $status = '<comment>update available</comment>';
        }
        $git_sha = null;
        $commit_date = null;
        if (null !== $lock_ref && null !== $lock_repo) {
            try {
                $recipe_commit_data = $this->github_api->find_recipe_commit_data_from_tree_ref($recipe->get_name(), $lock_repo, $lock_branch ?? '', $lock_version, $lock_ref);
                $git_sha = $recipe_commit_data ? $recipe_commit_data['commit'] : null;
                $commit_date = $recipe_commit_data ? $recipe_commit_data['date'] : null;
            } catch (Transport_Exception) {
                $io->write_error('Error downloading exact git sha for installed recipe.');
            }
        }
        $io->write('<info>name</info>             : ' . $recipe->get_name());
        $io->write('<info>version</info>          : ' . ($lock_version ?? 'n/a'));
        $io->write('<info>status</info>           : ' . $status);
        if (!$recipe->is_auto() && null !== $lock_version) {
            $recipe_url = \sprintf(
                'https://%s/tree/%s/%s/%s',
                $lock_repo,
                // if something fails, default to the branch as the closest "sha"
                $git_sha ?? $lock_branch,
                $recipe->get_name(),
                $lock_version
            );
            $io->write('<info>installed recipe</info> : ' . $recipe_url);
        }
        if ($lock_ref !== $recipe->get_ref()) {
            $io->write('<info>latest recipe</info>    : ' . $recipe->get_url());
        }
        if ($lock_ref !== $recipe->get_ref() && null !== $lock_version) {
            $history_url = \sprintf('https://%s/commits/%s/%s', $lock_repo, $lock_branch, $recipe->get_name());
            // show commits since one second after the currently-installed recipe
            if (null !== $commit_date) {
                $history_url .= '?since=';
                $history_url .= (new \DateTime($commit_date))->set_timezone(new \DateTimeZone('UTC'))->modify('+1 seconds')->format('Y-m-d\TH:i:s\Z');
            }
            $io->write('<info>recipe history</info>   : ' . $history_url);
        }
        if (null !== $lock_files) {
            $io->write('<info>files</info>            : ');
            $io->write('');
            $tree = $this->generate_files_tree($lock_files);
            $this->display_files_tree($tree);
        }
        if ($lock_ref !== $recipe->get_ref()) {
            $io->write(['', 'Update this recipe by running:', \sprintf('<info>composer recipes:update %s</info>', $recipe->get_name())]);
        }
    }
    private function generate_files_tree(array $files): array
    {
        $tree = [];
        foreach ($files as $file) {
            $path = explode('/', (string) $file);
            $tree = array_merge_recursive($tree, $this->add_node($path));
        }
        return $tree;
    }
    private function add_node(array $node): array
    {
        $current = array_shift($node);
        $sub_tree = [];
        if (null !== $current) {
            $sub_tree[$current] = $this->add_node($node);
        }
        return $sub_tree;
    }
    /**
     * Note : We do not display file modification information with Configurator like ComposerScripts, Container, DockerComposer, Dockerfile, Env, Gitignore and Makefile.
     */
    private function display_files_tree(array $tree): void
    {
        $end_key = array_key_last($tree);
        foreach ($tree as $dir => $files) {
            $tree_bar = '├';
            $total = \count($files);
            if (0 === $total || $end_key === $dir) {
                $tree_bar = '└';
            }
            $info = \sprintf('%s──%s', $tree_bar, $dir);
            $this->write_tree_line($info);
            $tree_bar = str_replace('└', ' ', $tree_bar);
            $this->display_tree($files, $tree_bar);
        }
    }
    private function display_tree(array $tree, string|array $previous_tree_bar = '├', int|float $level = 1): void
    {
        $previous_tree_bar = str_replace('├', '│', $previous_tree_bar);
        $tree_bar = $previous_tree_bar . '  ├';
        $i = 0;
        $total = \count($tree);
        foreach ($tree as $dir => $files) {
            ++$i;
            if ($i === $total) {
                $tree_bar = $previous_tree_bar . '  └';
            }
            $info = \sprintf('%s──%s', $tree_bar, $dir);
            $this->write_tree_line($info);
            $tree_bar = str_replace('└', ' ', $tree_bar);
            $this->display_tree($files, $tree_bar, $level + 1);
        }
    }
    private function write_tree_line(string $line): void
    {
        $io = $this->get_io();
        if (!$io->is_decorated()) {
            $line = str_replace(['└', '├', '──', '│'], ['`-', '|-', '-', '|'], $line);
        }
        $io->write($line);
    }
}