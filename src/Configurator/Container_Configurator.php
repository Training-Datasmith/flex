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
class Container_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $parameters, Lock $lock, array $options = []): void
    {
        $this->write('Setting parameters');
        $contents = $this->configure_parameters($parameters);
        file_put_contents($this->options->get('root-dir') . '/' . $this->get_services_path(), $contents);
    }
    public function unconfigure(Recipe $recipe, $parameters, Lock $lock): void
    {
        $this->write('Unsetting parameters');
        $target = $this->options->get('root-dir') . '/' . $this->get_services_path();
        $lines = $this->remove_parameters_from_lines(file($target), $parameters);
        file_put_contents($target, implode('', $lines));
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $recipe_update->set_original_file($this->get_services_path(), $this->configure_parameters($original_config, true));
        // for the new file, we need to update any values *and* remove any removed values
        $removed_parameters = [];
        foreach ($original_config as $name => $value) {
            if (!isset($new_config[$name])) {
                $removed_parameters[$name] = $value;
            }
        }
        $updated_file = $this->configure_parameters($new_config, true);
        $lines = $this->remove_parameters_from_lines(explode("\n", $updated_file), $removed_parameters);
        $recipe_update->set_new_file($this->get_services_path(), implode("\n", $lines));
    }
    private function configure_parameters(array $parameters, bool $update = false): string
    {
        $target = $this->options->get('root-dir') . '/' . $this->get_services_path();
        $end_at = 0;
        $is_parameters = false;
        $lines = [];
        foreach (file($target) as $i => $line) {
            $lines[] = $line;
            if (!$is_parameters && !preg_match('/^parameters:/', $line)) {
                continue;
            }
            if (!$is_parameters) {
                $is_parameters = true;
                continue;
            }
            if (!preg_match('/^\s+.*/', $line) && '' !== trim($line)) {
                $end_at = $i - 1;
                $is_parameters = false;
                continue;
            }
            foreach ($parameters as $key => $value) {
                $matches = [];
                if (preg_match(\sprintf('/^\s+%s\:/', preg_quote((string) $key, '/')), $line, $matches)) {
                    if ($update) {
                        $lines[$i] = substr($line, 0, \strlen($matches[0])) . ' ' . str_replace("'", "''", $value) . "\n";
                    }
                    unset($parameters[$key]);
                }
            }
        }
        if ($parameters) {
            $parameters_lines = [];
            if (!$end_at) {
                $parameters_lines[] = "parameters:\n";
            }
            foreach ($parameters as $key => $value) {
                if (\is_array($value)) {
                    $parameters_lines[] = \sprintf("    %s:\n%s", $key, $this->dump_yaml(2, $value));
                    continue;
                }
                $parameters_lines[] = \sprintf("    %s: '%s'%s", $key, str_replace("'", "''", $value), "\n");
            }
            if (!$end_at) {
                $parameters_lines[] = "\n";
            }
            array_splice($lines, $end_at, 0, $parameters_lines);
        }
        return implode('', $lines);
    }
    private function remove_parameters_from_lines(array $source_lines, array $parameters): array
    {
        $lines = [];
        foreach ($source_lines as $line) {
            if ($this->remove_parameters(1, $parameters, $line)) {
                continue;
            }
            $lines[] = $line;
        }
        return $lines;
    }
    private function remove_parameters(int|float $level, array $params, $line): bool
    {
        foreach ($params as $key => $value) {
            if (\is_array($value) && $this->remove_parameters($level + 1, $value, $line)) {
                return true;
            }
            if (preg_match(\sprintf('/^(\s{%d}|\t{%d})+%s\:/', 4 * $level, $level, preg_quote((string) $key, '/')), (string) $line)) {
                return true;
            }
        }
        return false;
    }
    private function dump_yaml(int|float $level, array $array): string
    {
        $line = '';
        foreach ($array as $key => $value) {
            $line .= str_repeat('    ', $level);
            if (!\is_array($value)) {
                $line .= \sprintf("%s: '%s'\n", $key, str_replace("'", "''", $value));
                continue;
            }
            $line .= \sprintf("%s:\n", $key) . $this->dump_yaml($level + 1, $value);
        }
        return $line;
    }
    private function get_services_path(): string
    {
        return $this->options->expand_target_dir('%CONFIG_DIR%/services.yaml');
    }
}