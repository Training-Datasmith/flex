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
 * Adds commands to a Dockerfile.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class Dockerfile_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        if (!Docker_Compose_Configurator::should_configure_docker_recipe($this->composer, $this->io, $recipe)) {
            return;
        }
        $this->configure_dockerfile($recipe, $config, $options['force'] ?? false);
    }
    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        if (!file_exists($dockerfile = $this->options->get('root-dir') . '/Dockerfile')) {
            return;
        }
        $name = $recipe->get_name();
        $contents = preg_replace(\sprintf('{%s+###> %s ###.*?###< %s ###%s+}s', "\n", $name, $name, "\n"), "\n", file_get_contents($dockerfile), -1, $count);
        if (!$count) {
            return;
        }
        $this->write('Removing Dockerfile entries');
        file_put_contents($dockerfile, ltrim((string) $contents, "\n"));
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        if (!Docker_Compose_Configurator::should_configure_docker_recipe($this->composer, $this->io, $recipe_update->get_new_recipe())) {
            return;
        }
        $recipe_update->set_original_file('Dockerfile', $this->get_contents_after_applying_recipe($recipe_update->get_original_recipe(), $original_config));
        $recipe_update->set_new_file('Dockerfile', $this->get_contents_after_applying_recipe($recipe_update->get_new_recipe(), $new_config));
    }
    private function configure_dockerfile(Recipe $recipe, array $config, bool $update, bool $write_output = true): void
    {
        $dockerfile = $this->options->get('root-dir') . '/Dockerfile';
        if (!file_exists($dockerfile) || !$update && $this->is_file_marked($recipe, $dockerfile)) {
            return;
        }
        if ($write_output) {
            $this->write('Adding Dockerfile entries');
        }
        $data = ltrim($this->mark_data($recipe, implode("\n", $config)), "\n");
        if ($this->update_data($dockerfile, $data)) {
            // done! Existing spot updated
            return;
        }
        $lines = [];
        foreach (file($dockerfile) as $line) {
            $lines[] = $line;
            if (!preg_match('/^###> recipes ###$/', $line)) {
                continue;
            }
            $lines[] = $data;
        }
        file_put_contents($dockerfile, implode('', $lines));
    }
    private function get_contents_after_applying_recipe(Recipe $recipe, array $config): ?string
    {
        if (0 === \count($config)) {
            return null;
        }
        $dockerfile = $this->options->get('root-dir') . '/Dockerfile';
        $original_contents = file_exists($dockerfile) ? file_get_contents($dockerfile) : null;
        $this->configure_dockerfile($recipe, $config, true, false);
        $updated_contents = file_exists($dockerfile) ? file_get_contents($dockerfile) : null;
        if (null === $original_contents) {
            if (file_exists($dockerfile)) {
                unlink($dockerfile);
            }
        } else {
            file_put_contents($dockerfile, $original_contents);
        }
        return $updated_contents;
    }
}