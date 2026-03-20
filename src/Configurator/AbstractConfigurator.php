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

use Composer\IO\Io_Interface;
use Symfony\Flex\Lock;
use Symfony\Flex\Path;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
abstract class Abstract_Configurator
{
    protected \Symfony\Flex\Path $path;
    public function __construct(protected \Composer\Composer $composer, protected \Composer\IO\Io_Interface $io, protected \Symfony\Flex\Options $options)
    {
        $this->path = new Path($this->options->get('root-dir'));
    }
    abstract public function configure(Recipe $recipe, $config, Lock $lock, array $options = []);
    abstract public function unconfigure(Recipe $recipe, $config, Lock $lock);
    abstract public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void;
    protected function write($messages, $verbosity = Io_Interface::VERBOSE)
    {
        if (!\is_array($messages)) {
            $messages = [$messages];
        }
        foreach ($messages as $i => $message) {
            $messages[$i] = '    ' . $message;
        }
        $this->io->write_error($messages, true, $verbosity);
    }
    protected function is_file_marked(Recipe $recipe, string $file): bool
    {
        return is_file($file) && str_contains(file_get_contents($file), \sprintf('###> %s ###', $recipe->get_name()));
    }
    protected function mark_data(Recipe $recipe, string $data): string
    {
        return "\n" . \sprintf('###> %s ###%s%s%s###< %s ###%s', $recipe->get_name(), "\n", rtrim($data, "\r\n"), "\n", $recipe->get_name(), "\n");
    }
    protected function is_file_xml_marked(Recipe $recipe, string $file): bool
    {
        return is_file($file) && str_contains(file_get_contents($file), \sprintf('###+ %s ###', $recipe->get_name()));
    }
    protected function mark_xml_data(Recipe $recipe, string $data): string
    {
        return "\n" . \sprintf('        <!-- ###+ %s ### -->%s%s%s        <!-- ###- %s ### -->%s', $recipe->get_name(), "\n", rtrim($data, "\r\n"), "\n", $recipe->get_name(), "\n");
    }
    /**
     * @return bool True if section was found and replaced
     */
    protected function update_data(string $file, string $data): bool
    {
        if (!file_exists($file)) {
            return false;
        }
        $contents = file_get_contents($file);
        $new_contents = $this->update_data_string($contents, $data);
        if (null === $new_contents) {
            return false;
        }
        file_put_contents($file, $new_contents);
        return true;
    }
    /**
     * @return string|null returns the updated content if the section was found, null if not found
     */
    protected function update_data_string(string $contents, string $data): ?string
    {
        $pieces = explode("\n", trim($data));
        $start_mark = trim(reset($pieces));
        $end_mark = trim(end($pieces));
        if (!str_contains($contents, $start_mark) || !str_contains($contents, $end_mark)) {
            return null;
        }
        $pattern = '/' . preg_quote($start_mark, '/') . '.*?' . preg_quote($end_mark, '/') . '/s';
        return preg_replace($pattern, trim($data), $contents);
    }
    protected function extract_section(Recipe $recipe, string $contents): ?string
    {
        $section = $this->mark_data($recipe, '----');
        $pieces = explode("\n", trim($section));
        $start_mark = trim(reset($pieces));
        $end_mark = trim(end($pieces));
        $pattern = '/' . preg_quote($start_mark, '/') . '.*?' . preg_quote($end_mark, '/') . '/s';
        $matches = [];
        preg_match($pattern, $contents, $matches);
        return $matches[0] ?? null;
    }
}