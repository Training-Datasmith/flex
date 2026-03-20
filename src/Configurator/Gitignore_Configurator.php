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
class Gitignore_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $vars, Lock $lock, array $options = []): void
    {
        $this->write('Adding entries to .gitignore');
        $this->configure_gitignore($recipe, $vars, $options['force'] ?? false);
    }
    public function unconfigure(Recipe $recipe, $vars, Lock $lock): void
    {
        $file = $this->options->get('root-dir') . '/.gitignore';
        if (!file_exists($file)) {
            return;
        }
        $contents = preg_replace(\sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->get_name(), $recipe->get_name(), "\n"), "\n", file_get_contents($file), -1, $count);
        if (!$count) {
            return;
        }
        $this->write('Removing entries in .gitignore');
        file_put_contents($file, ltrim((string) $contents, "\r\n"));
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $recipe_update->set_original_file('.gitignore', $this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_original_recipe(), $original_config));
        $recipe_update->set_new_file('.gitignore', $this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_new_recipe(), $new_config));
    }
    private function configure_gitignore(Recipe $recipe, array $vars, bool $update): void
    {
        $gitignore = $this->options->get('root-dir') . '/.gitignore';
        if (!$update && $this->is_file_marked($recipe, $gitignore)) {
            return;
        }
        $data = '';
        foreach ($vars as $value) {
            $value = $this->options->expand_target_dir($value);
            $data .= "{$value}\n";
        }
        $data = "\n" . ltrim($this->mark_data($recipe, $data), "\r\n");
        if (!$this->update_data($gitignore, $data)) {
            file_put_contents($gitignore, $data, \FILE_APPEND);
        }
    }
    private function get_contents_after_applying_recipe(string $root_dir, Recipe $recipe, array $vars): ?string
    {
        if (0 === \count($vars)) {
            return null;
        }
        $file = $root_dir . '/.gitignore';
        $original_contents = file_exists($file) ? file_get_contents($file) : null;
        $this->configure_gitignore($recipe, $vars, true);
        $updated_contents = file_exists($file) ? file_get_contents($file) : null;
        if (null === $original_contents) {
            if (file_exists($file)) {
                unlink($file);
            }
        } else {
            file_put_contents($file, $original_contents);
        }
        return $updated_contents;
    }
}