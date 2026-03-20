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
class Makefile_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $definitions, Lock $lock, array $options = []): void
    {
        $this->write('Adding Makefile entries');
        $this->configure_makefile($recipe, $definitions, $options['force'] ?? false);
    }
    public function unconfigure(Recipe $recipe, $vars, Lock $lock): void
    {
        if (!file_exists($makefile = $this->options->get('root-dir') . '/Makefile')) {
            return;
        }
        $contents = preg_replace(\sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->get_name(), $recipe->get_name(), "\n"), "\n", file_get_contents($makefile), -1, $count);
        if (!$count) {
            return;
        }
        $this->write(\sprintf('Removing Makefile entries from %s', $makefile));
        if (!trim((string) $contents)) {
            @unlink($makefile);
        } else {
            file_put_contents($makefile, ltrim((string) $contents, "\r\n"));
        }
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $recipe_update->set_original_file('Makefile', $this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_original_recipe(), $original_config));
        $recipe_update->set_new_file('Makefile', $this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_new_recipe(), $new_config));
    }
    private function configure_makefile(Recipe $recipe, array $definitions, bool $update): void
    {
        $makefile = $this->options->get('root-dir') . '/Makefile';
        if (!$update && $this->is_file_marked($recipe, $makefile)) {
            return;
        }
        $data = $this->options->expand_target_dir(implode("\n", $definitions));
        $data = $this->mark_data($recipe, $data);
        $data = "\n" . ltrim($data, "\r\n");
        if (!file_exists($makefile)) {
            $env_key = $this->options->get('runtime')['env_var_name'] ?? 'APP_ENV';
            $dotenv_path = $this->options->get('runtime')['dotenv_path'] ?? '.env';
            file_put_contents($this->options->get('root-dir') . '/Makefile', <<<EOF
            ifndef {$env_key}
                include {$dotenv_path}
            endif
            
            .DEFAULT_GOAL := help
            .PHONY: help
            help:
                @awk 'BEGIN {FS = ":.*?## "}; /^[a-zA-Z-]+:.*?## .*\$\$/ {printf "\x1b[32m%-15s\x1b[0m %s\\n", \$\$1, \$\$2}' Makefile | sort
            
            EOF);
        }
        if (!$this->update_data($makefile, $data)) {
            file_put_contents($makefile, $data, \FILE_APPEND);
        }
    }
    private function get_contents_after_applying_recipe(string $root_dir, Recipe $recipe, array $definitions): ?string
    {
        if (0 === \count($definitions)) {
            return null;
        }
        $file = $root_dir . '/Makefile';
        $original_contents = file_exists($file) ? file_get_contents($file) : null;
        $this->configure_makefile($recipe, $definitions, true);
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