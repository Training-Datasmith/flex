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
namespace Symfony\Flex\Configurator;

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Copy_From_Package_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        $this->write('Copying files from package');
        $package_dir = $this->composer->get_installation_manager()->get_install_path($recipe->get_package());
        $options = array_merge($this->options->to_array(), $options);
        $files = $this->get_files_to_copy($config, $package_dir);
        foreach ($files as $source => $target) {
            $this->copy_file($source, $target, $options);
        }
    }
    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        $this->write('Removing files from package');
        $package_dir = $this->composer->get_installation_manager()->get_install_path($recipe->get_package());
        $this->remove_files($config, $package_dir, $this->options->get('root-dir'));
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $package_dir = $this->composer->get_installation_manager()->get_install_path($recipe_update->get_new_recipe()->get_package());
        foreach ($original_config as $source => $target) {
            if (isset($new_config[$source])) {
                // path is in both, we cannot update
                $recipe_update->add_copy_from_package_path($package_dir . '/' . $source, $this->options->expand_target_dir($target));
                unset($new_config[$source]);
            }
            // if any paths were removed from the recipe, we'll keep them
        }
        // any remaining files are new, and we can copy them
        foreach ($this->get_files_to_copy($new_config, $package_dir) as $source => $target) {
            if (!file_exists($source)) {
                throw new \LogicException(\sprintf('File "%s" does not exist!', $source));
            }
            $recipe_update->set_new_file($target, file_get_contents($source));
        }
    }
    private function get_files_to_copy(array $manifest, string $from): array
    {
        $files = [];
        foreach ($manifest as $source => $target) {
            $target = $this->options->expand_target_dir($target);
            if (str_ends_with((string) $source, '/')) {
                $files = array_merge($files, $this->get_files_for_dir($this->path->concatenate([$from, $source]), $target));
                continue;
            }
            $files[$this->path->concatenate([$from, $source])] = $target;
        }
        return $files;
    }
    private function remove_files(array $manifest, string $from, string $to): void
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expand_target_dir($target);
            if (str_ends_with((string) $source, '/')) {
                $this->remove_files_from_dir($this->path->concatenate([$from, $source]), $this->path->concatenate([$to, $target]));
            } else {
                $target_path = $this->path->concatenate([$to, $target]);
                if (file_exists($target_path)) {
                    @unlink($target_path);
                    $this->write(\sprintf('  Removed <fg=green>"%s"</>', $this->path->relativize($target_path)));
                }
            }
        }
    }
    private function get_files_for_dir(string $source, string $target): array
    {
        $iterator = $this->create_source_iterator($source, \Recursive_Iterator_Iterator::SELF_FIRST);
        $files = [];
        foreach ($iterator as $item) {
            $target_path = $this->path->concatenate([$target, $iterator->get_sub_path_name()]);
            $files[(string) $item] = $target_path;
        }
        return $files;
    }
    /**
     * @param string $source The absolute path to the source file
     * @param string $target The relative (to root dir) path to the target
     */
    public function copy_file(string $source, string $target, array $options): void
    {
        $target = $this->options->get('root-dir') . '/' . $this->options->expand_target_dir($target);
        if (is_dir($source)) {
            // directory will be created when a file is copied to it
            return;
        }
        if (!$this->options->should_write_file($target, $options['force'] ?? false, $options['assumeYesForPrompts'] ?? false)) {
            return;
        }
        if (!file_exists($source)) {
            throw new \LogicException(\sprintf('File "%s" does not exist!', $source));
        }
        if (!file_exists(\dirname($target))) {
            mkdir(\dirname($target), 0777, true);
            $this->write(\sprintf('  Created <fg=green>"%s"</>', $this->path->relativize(\dirname($target))));
        }
        file_put_contents($target, $this->options->expand_target_dir(file_get_contents($source)));
        @chmod($target, fileperms($target) | fileperms($source) & 0111);
        $this->write(\sprintf('  Created <fg=green>"%s"</>', $this->path->relativize($target)));
    }
    private function remove_files_from_dir(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }
        $iterator = $this->create_source_iterator($source, \Recursive_Iterator_Iterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $target_path = $this->path->concatenate([$target, $iterator->get_sub_path_name()]);
            if ($item->is_dir()) {
                // that removes the dir only if it is empty
                @rmdir($target_path);
                $this->write(\sprintf('  Removed directory <fg=green>"%s"</>', $this->path->relativize($target_path)));
            } else {
                @unlink($target_path);
                $this->write(\sprintf('  Removed <fg=green>"%s"</>', $this->path->relativize($target_path)));
            }
        }
    }
    private function create_source_iterator(string $source, int $mode): \Recursive_Iterator_Iterator
    {
        return new \Recursive_Iterator_Iterator(new \Recursive_Directory_Iterator($source, \Recursive_Directory_Iterator::SKIP_DOTS), $mode);
    }
}