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
class Copy_From_Recipe_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        $this->write('Copying files from recipe');
        $options = array_merge($this->options->to_array(), $options);
        $lock->add($recipe->get_name(), ['files' => $this->copy_files($config, $recipe->get_files(), $options)]);
    }
    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        $this->write('Removing files from recipe');
        $root_dir = $this->options->get('root-dir');
        foreach ($this->options->get_removable_files($recipe, $lock) as $file) {
            if ('.git' !== $file) {
                // never remove the main Git directory, even if it was created by a recipe
                $this->remove_file($this->path->concatenate([$root_dir, $file]));
            }
        }
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        foreach ($recipe_update->get_original_recipe()->get_files() as $filename => $data) {
            $filename = $this->resolve_target_folder($filename, $original_config);
            $recipe_update->set_original_file($filename, $data['contents']);
        }
        $files = [];
        foreach ($recipe_update->get_new_recipe()->get_files() as $filename => $data) {
            $filename = $this->resolve_target_folder($filename, $new_config);
            $recipe_update->set_new_file($filename, $data['contents']);
            $files[] = $this->get_local_file_path($recipe_update->get_root_dir(), $filename);
        }
        $recipe_update->get_lock()->add($recipe_update->get_package_name(), ['files' => $files]);
    }
    /**
     * @param array<string, string> $config
     */
    private function resolve_target_folder(string $path, array $config): string
    {
        foreach ($config as $key => $target) {
            if (str_starts_with($path, $key)) {
                return $this->options->expand_target_dir($target) . substr($path, \strlen($key));
            }
        }
        return $path;
    }
    private function copy_files(array $manifest, array $files, array $options): array
    {
        $copied_files = [];
        $to = $options['root-dir'] ?? '.';
        foreach ($manifest as $source => $target) {
            $target = $this->options->expand_target_dir($target);
            if (str_ends_with((string) $source, '/')) {
                $copied_files = array_merge($copied_files, $this->copy_dir($source, $this->path->concatenate([$to, $target]), $files, $options));
            } else {
                $copied_files[] = $this->copy_file($this->path->concatenate([$to, $target]), $files[$source]['contents'], $files[$source]['executable'], $options);
            }
        }
        return $copied_files;
    }
    private function copy_dir(string $source, string $target, array $files, array $options): array
    {
        $copied_files = [];
        foreach ($files as $file => $data) {
            if (str_starts_with((string) $file, $source)) {
                $file = $this->path->concatenate([$target, substr((string) $file, \strlen($source))]);
                $copied_files[] = $this->copy_file($file, $data['contents'], $data['executable'], $options);
            }
        }
        return $copied_files;
    }
    private function copy_file(string $to, string $contents, bool $executable, array $options): string
    {
        $base_path = $options['root-dir'] ?? '.';
        $copied_file = $this->get_local_file_path($base_path, $to);
        if (!$this->options->should_write_file($to, $options['force'] ?? false, $options['assumeYesForPrompts'] ?? false)) {
            return $copied_file;
        }
        if (!is_dir(\dirname($to))) {
            mkdir(\dirname($to), 0777, true);
        }
        file_put_contents($to, $this->options->expand_target_dir($contents));
        if ($executable) {
            @chmod($to, fileperms($to) | 0111);
        }
        $this->write(\sprintf('  Created <fg=green>"%s"</>', $this->path->relativize($to)));
        return $copied_file;
    }
    private function remove_file(string $to): void
    {
        if (!file_exists($to)) {
            return;
        }
        @unlink($to);
        $this->write(\sprintf('  Removed <fg=green>"%s"</>', $this->path->relativize($to)));
        if (0 === \count(glob(\dirname($to) . '/*', \GLOB_NOSORT))) {
            @rmdir(\dirname($to));
        }
    }
    private function get_local_file_path(string $base_path, string $destination): string
    {
        return str_replace($base_path . \DIRECTORY_SEPARATOR, '', $destination);
    }
}