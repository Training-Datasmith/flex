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

use Composer\Composer;
use Composer\IO\Io_Interface;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Env_Configurator extends Abstract_Configurator
{
    public function __construct(Composer $composer, Io_Interface $io, Options $options, private readonly string $suffix = '')
    {
        parent::__construct($composer, $io, $options);
    }
    public function configure(Recipe $recipe, $vars, Lock $lock, array $options = []): void
    {
        $this->write('Adding environment variable defaults' . ('' === $this->suffix ? '' : ' (' . $this->suffix . ')'));
        $this->configure_env_dist($recipe, $vars, $options['force'] ?? false);
        if ('' !== $this->suffix) {
            return;
        }
        if (!file_exists($this->options->get('root-dir') . '/' . ($this->options->get('runtime')['dotenv_path'] ?? '.env') . '.test')) {
            $this->configure_php_unit($recipe, $vars, $options['force'] ?? false);
        }
    }
    public function unconfigure(Recipe $recipe, $vars, Lock $lock): void
    {
        $this->unconfigure_env_files($recipe);
        $this->unconfigure_php_unit($recipe);
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $recipe_update->add_original_files($this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_original_recipe(), $original_config));
        $recipe_update->add_new_files($this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_new_recipe(), $new_config));
    }
    private function configure_env_dist(Recipe $recipe, $vars, bool $update): void
    {
        $dotenv_path = $this->options->get('runtime')['dotenv_path'] ?? '.env';
        $files = '' === $this->suffix ? [$dotenv_path . '.dist', $dotenv_path] : [$dotenv_path . '.' . $this->suffix];
        foreach ($files as $file) {
            $env = $this->options->get('root-dir') . '/' . $file;
            if (!is_file($env)) {
                continue;
            }
            if (!$update && $this->is_file_marked($recipe, $env)) {
                continue;
            }
            $data = '';
            foreach ($vars as $key => $value) {
                $existing_value = $update ? $this->find_existing_value($key, $env, $recipe) : null;
                $value = $this->evaluate_value($value, $existing_value);
                if ('#' === $key[0] && is_numeric(substr((string) $key, 1))) {
                    if ('' === $value) {
                        $data .= "#\n";
                    } else {
                        $data .= '# ' . $value . "\n";
                    }
                    continue;
                }
                $value = $this->options->expand_target_dir($value);
                if (false !== strpbrk((string) $value, " \t\n&!\"")) {
                    $value = '"' . str_replace(['\\', '"', "\t", "\n"], ['\\\\', '\"', '\t', '\n'], $value) . '"';
                }
                $data .= "{$key}={$value}\n";
            }
            $data = $this->mark_data($recipe, $data);
            if (!$this->update_data($env, $data)) {
                file_put_contents($env, $data, \FILE_APPEND);
            }
        }
    }
    private function configure_php_unit(Recipe $recipe, $vars, bool $update): void
    {
        foreach (['phpunit.xml.dist', 'phpunit.dist.xml', 'phpunit.xml'] as $file) {
            $phpunit = $this->options->get('root-dir') . '/' . $file;
            if (!is_file($phpunit)) {
                continue;
            }
            if (!$update && $this->is_file_xml_marked($recipe, $phpunit)) {
                continue;
            }
            $data = '';
            foreach ($vars as $key => $value) {
                $value = $this->evaluate_value($value);
                if ('#' === $key[0]) {
                    if (is_numeric(substr((string) $key, 1))) {
                        $doc = new \Dom_Document();
                        $data .= '        ' . $doc->save_xml($doc->create_comment(' ' . $value . ' ')) . "\n";
                    } else {
                        $value = $this->options->expand_target_dir($value);
                        $doc = new \Dom_Document();
                        $fragment = $doc->create_element('env');
                        $fragment->set_attribute('name', substr((string) $key, 1));
                        $fragment->set_attribute('value', $value);
                        $data .= '        ' . str_replace(['<', '/>'], ['<!-- ', ' -->'], $doc->save_xml($fragment)) . "\n";
                    }
                } else {
                    $value = $this->options->expand_target_dir($value);
                    $doc = new \Dom_Document();
                    $fragment = $doc->create_element('env');
                    $fragment->set_attribute('name', $key);
                    $fragment->set_attribute('value', $value);
                    $data .= '        ' . $doc->save_xml($fragment) . "\n";
                }
            }
            $data = $this->mark_xml_data($recipe, $data);
            if (!$this->update_data($phpunit, $data)) {
                file_put_contents($phpunit, preg_replace('{^(\s+</php>)}m', $data . '$1', file_get_contents($phpunit)));
            }
        }
    }
    private function unconfigure_env_files(Recipe $recipe): void
    {
        $dotenv_path = $this->options->get('runtime')['dotenv_path'] ?? '.env';
        $files = '' === $this->suffix ? [$dotenv_path, $dotenv_path . '.dist'] : [$dotenv_path . '.' . $this->suffix];
        foreach ($files as $file) {
            $env = $this->options->get('root-dir') . '/' . $file;
            if (!file_exists($env)) {
                continue;
            }
            $contents = preg_replace(\sprintf('{%s*###> %s ###.*###< %s ###%s+}s', "\n", $recipe->get_name(), $recipe->get_name(), "\n"), "\n", file_get_contents($env), -1, $count);
            if (!$count) {
                continue;
            }
            $this->write(\sprintf('Removing environment variables from %s', $file));
            file_put_contents($env, $contents);
        }
    }
    private function unconfigure_php_unit(Recipe $recipe): void
    {
        foreach (['phpunit.dist.xml', 'phpunit.xml.dist', 'phpunit.xml'] as $file) {
            $phpunit = $this->options->get('root-dir') . '/' . $file;
            if (!is_file($phpunit)) {
                continue;
            }
            $contents = preg_replace(\sprintf('{%s*\s+<!-- ###\+ %s ### -->.*<!-- ###- %s ### -->%s+}s', "\n", $recipe->get_name(), $recipe->get_name(), "\n"), "\n", file_get_contents($phpunit), -1, $count);
            if (!$count) {
                continue;
            }
            $this->write(\sprintf('Removing environment variables from %s', $file));
            file_put_contents($phpunit, $contents);
        }
    }
    /**
     * Evaluates expressions like %generate(secret)%.
     *
     * If $originalValue is passed, and the value contains an expression.
     * the $originalValue is used.
     */
    private function evaluate_value($value, ?string $original_value = null)
    {
        if ('%generate(secret)%' === $value) {
            if (null !== $original_value) {
                return $original_value;
            }
            return $this->generate_random_bytes();
        }
        if (preg_match('~^%generate\(secret,\s*([0-9]+)\)%$~', (string) $value, $matches)) {
            if (null !== $original_value) {
                return $original_value;
            }
            return $this->generate_random_bytes($matches[1]);
        }
        return $value;
    }
    private function generate_random_bytes($length = 16): string
    {
        return bin2hex(random_bytes($length));
    }
    private function get_contents_after_applying_recipe(string $root_dir, Recipe $recipe, array $vars): array
    {
        $dotenv_path = $this->options->get('runtime')['dotenv_path'] ?? '.env';
        $files = '' === $this->suffix ? [$dotenv_path, $dotenv_path . '.dist', 'phpunit.dist.xml', 'phpunit.xml.dist', 'phpunit.xml'] : [$dotenv_path . '.' . $this->suffix];
        if (0 === \count($vars)) {
            return array_fill_keys($files, null);
        }
        $original_contents = [];
        foreach ($files as $file) {
            $original_contents[$file] = file_exists($root_dir . '/' . $file) ? file_get_contents($root_dir . '/' . $file) : null;
        }
        $this->configure_env_dist($recipe, $vars, true);
        if ('' === $this->suffix && !file_exists($root_dir . '/' . $dotenv_path . '.test')) {
            $this->configure_php_unit($recipe, $vars, true);
        }
        $updated_contents = [];
        foreach ($files as $file) {
            $updated_contents[$file] = file_exists($root_dir . '/' . $file) ? file_get_contents($root_dir . '/' . $file) : null;
        }
        foreach ($original_contents as $file => $contents) {
            if (null === $contents) {
                if (file_exists($root_dir . '/' . $file)) {
                    unlink($root_dir . '/' . $file);
                }
            } else {
                file_put_contents($root_dir . '/' . $file, $contents);
            }
        }
        return $updated_contents;
    }
    /**
     * Attempts to find the existing value of an environment variable.
     */
    private function find_existing_value(string $var, string $filename, Recipe $recipe): ?string
    {
        if (!file_exists($filename)) {
            return null;
        }
        $contents = file_get_contents($filename);
        $section = $this->extract_section($recipe, $contents);
        if (!$section) {
            return null;
        }
        $lines = explode("\n", $section);
        foreach ($lines as $line) {
            if (!str_starts_with($line, \sprintf('%s=', $var))) {
                continue;
            }
            return trim(substr($line, \strlen($var) + 1));
        }
        return null;
    }
}