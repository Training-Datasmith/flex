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
use Composer\Factory;
use Composer\IO\Io_Interface;
use Composer\Json\Json_File;
use Composer\Json\Json_Manipulator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * Adds services and volumes to compose.yaml file.
 *
 * @author Kévin Dunglas <kevin@dunglas.dev>
 */
class Docker_Compose_Configurator extends Abstract_Configurator
{
    private readonly \Symfony\Component\Filesystem\Filesystem $filesystem;
    public static $configure_docker_recipes;
    public function __construct(Composer $composer, Io_Interface $io, Options $options)
    {
        parent::__construct($composer, $io, $options);
        $this->filesystem = new Filesystem();
    }
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        if (!self::should_configure_docker_recipe($this->composer, $this->io, $recipe)) {
            return;
        }
        $this->configure_docker_compose($recipe, $config, $options['force'] ?? false);
        $this->write('Docker Compose definitions have been modified. Please run "docker compose up --build" again to apply the changes.');
    }
    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        $root_dir = $this->options->get('root-dir');
        foreach ($this->normalize_config($config) as $file => $extra) {
            if (null === $docker_compose_file = $this->find_docker_compose_file($root_dir, $file)) {
                continue;
            }
            $name = $recipe->get_name();
            // Remove recipe and add break line
            $contents = preg_replace(\sprintf('{%s+###> %s ###.*?###< %s ###%s+}s', "\n", $name, $name, "\n"), \PHP_EOL . \PHP_EOL, file_get_contents($docker_compose_file), -1, $count);
            if (!$count) {
                return;
            }
            foreach ($extra as $key => $value) {
                if (0 === preg_match(\sprintf('{^%s:[ \t\r\n]*([ \t]+\w|#)}m', $key), (string) $contents, $matches)) {
                    $contents = preg_replace(\sprintf('{\n?^%s:[ \t\r\n]*}sm', $key), '', (string) $contents, -1, $count);
                }
            }
            $this->write(\sprintf('Removing Docker Compose entries from "%s"', $docker_compose_file));
            file_put_contents($docker_compose_file, ltrim((string) $contents, "\n"));
        }
        $this->write('Docker Compose definitions have been modified. Please run "docker compose up" again to apply the changes.');
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        if (!self::should_configure_docker_recipe($this->composer, $this->io, $recipe_update->get_new_recipe())) {
            return;
        }
        $recipe_update->add_original_files($this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_original_recipe(), $original_config));
        $recipe_update->add_new_files($this->get_contents_after_applying_recipe($recipe_update->get_root_dir(), $recipe_update->get_new_recipe(), $new_config));
    }
    public static function should_configure_docker_recipe(Composer $composer, Io_Interface $io, Recipe $recipe): bool
    {
        if (null !== self::$configure_docker_recipes) {
            return self::$configure_docker_recipes;
        }
        if (null !== $docker_preference = $composer->get_package()->get_extra()['symfony']['docker'] ?? null) {
            self::$configure_docker_recipes = filter_var($docker_preference, \FILTER_VALIDATE_BOOLEAN);
            return self::$configure_docker_recipes;
        }
        if ('install' !== $recipe->get_job()) {
            // default to not configuring
            return false;
        }
        if (!isset($_SERVER['SYMFONY_DOCKER'])) {
            $answer = self::ask_docker_support($io, $recipe);
        } elseif (filter_var($_SERVER['SYMFONY_DOCKER'], \FILTER_VALIDATE_BOOLEAN)) {
            $answer = 'p';
        } else {
            $answer = 'x';
        }
        if ('n' === $answer) {
            self::$configure_docker_recipes = false;
            return self::$configure_docker_recipes;
        }
        if ('y' === $answer) {
            self::$configure_docker_recipes = true;
            return self::$configure_docker_recipes;
        }
        // yes or no permanently
        self::$configure_docker_recipes = 'p' === $answer;
        $json = new Json_File(Factory::get_composer_file());
        $manipulator = new Json_Manipulator(file_get_contents($json->get_path()));
        $manipulator->add_sub_node('extra', 'symfony.docker', self::$configure_docker_recipes);
        file_put_contents($json->get_path(), $manipulator->get_contents());
        return self::$configure_docker_recipes;
    }
    /**
     * Normalizes the config and return the name of the main Docker Compose file if applicable.
     */
    private function normalize_config(array $config): array
    {
        foreach ($config as $key => $val) {
            // Support for the short recipe syntax that modifies compose.yaml only
            if (isset($val[0])) {
                return ['compose.yaml' => $config];
            }
            if (!str_starts_with((string) $key, 'docker-')) {
                continue;
            }
            // If the recipe still use the legacy "docker-compose.yml" names, remove the "docker-" prefix and change the extension
            $new_key = pathinfo(substr((string) $key, 7), \PATHINFO_FILENAME) . '.yaml';
            $config[$new_key] = $val;
            unset($config[$key]);
        }
        return $config;
    }
    /**
     * Finds the Docker Compose file according to these rules: https://docs.docker.com/compose/reference/envvars/#compose_file.
     */
    private function find_docker_compose_file(string $root_dir, string $file): ?string
    {
        if (isset($_SERVER['COMPOSE_FILE'])) {
            $filename_to_find = pathinfo($file, \PATHINFO_FILENAME);
            $separator = $_SERVER['COMPOSE_PATH_SEPARATOR'] ?? ('\\' === \DIRECTORY_SEPARATOR ? ';' : ':');
            $files = explode($separator, (string) $_SERVER['COMPOSE_FILE']);
            foreach ($files as $f) {
                $filename = pathinfo($f, \PATHINFO_FILENAME);
                if ($filename !== $filename_to_find && "docker-{$filename_to_find}" !== $filename) {
                    continue;
                }
                if (!$this->filesystem->is_absolute_path($f)) {
                    $f = realpath(\sprintf('%s/%s', $root_dir, $f));
                }
                if ($this->filesystem->exists($f)) {
                    return $f;
                }
            }
        }
        // COMPOSE_FILE not set, or doesn't contain the file we're looking for
        $dir = $root_dir;
        do {
            if ($this->filesystem->exists($docker_compose_file = \sprintf('%s/%s', $dir, $file)) || $this->filesystem->exists($docker_compose_file = substr($docker_compose_file, 0, -3) . 'ml') || $this->filesystem->exists($docker_compose_file = \sprintf('%s/docker-%s', $dir, $file)) || $this->filesystem->exists($docker_compose_file = substr($docker_compose_file, 0, -3) . 'ml')) {
                return $docker_compose_file;
            }
            $previous_dir = $dir;
            $dir = \dirname($dir);
        } while ($dir !== $previous_dir);
        return null;
    }
    private function parse(int|float $level, $indent, $services): string
    {
        $line = '';
        foreach ($services as $key => $value) {
            $line .= str_repeat(' ', $indent * $level);
            if (!\is_array($value)) {
                if (\is_string($key)) {
                    $line .= \sprintf('%s:', $key);
                }
                $line .= \sprintf("%s\n", $value);
                continue;
            }
            $line .= \sprintf("%s:\n", $key) . $this->parse($level + 1, $indent, $value);
        }
        return $line;
    }
    private function configure_docker_compose(Recipe $recipe, array $config, bool $update): void
    {
        $root_dir = $this->options->get('root-dir');
        foreach ($this->normalize_config($config) as $file => $extra) {
            $docker_compose_file = $this->find_docker_compose_file($root_dir, $file);
            if (null === $docker_compose_file) {
                $docker_compose_file = $root_dir . '/' . $file;
                file_put_contents($docker_compose_file, '');
                $this->write(\sprintf('  Created <fg=green>"%s"</>', $file));
            }
            if (!$update && $this->is_file_marked($recipe, $docker_compose_file)) {
                continue;
            }
            $this->write(\sprintf('Adding Docker Compose definitions to "%s"', $docker_compose_file));
            $offset = 2;
            $node = null;
            $end_at = [];
            $start_at = [];
            $lines = [];
            $nodes_lines = [];
            foreach (file($docker_compose_file) as $i => $line) {
                $lines[] = $line;
                $ltrimed_line = ltrim($line, ' ');
                if (null !== $node) {
                    $nodes_lines[$node][$i] = $line;
                }
                // Skip blank lines and comments
                if ('' !== $ltrimed_line && str_starts_with($ltrimed_line, '#')) {
                    continue;
                }
                if ('' === trim($line)) {
                    continue;
                }
                // Extract Docker Compose keys (usually "services" and "volumes")
                if (!preg_match('/^[\'"]?([a-zA-Z0-9]+)[\'"]?:\s*$/', $line, $matches)) {
                    // Detect indentation to use
                    $offest_line = \strlen($line) - \strlen($ltrimed_line);
                    if ($offset > $offest_line && 0 !== $offest_line) {
                        $offset = $offest_line;
                    }
                    continue;
                }
                // Keep end in memory (check break line on previous line)
                $end_at[$node] = !$i || '' !== trim($lines[$i - 1]) ? $i : $i - 1;
                $node = $matches[1];
                if (!isset($nodes_lines[$node])) {
                    $nodes_lines[$node] = [];
                }
                if (!isset($start_at[$node])) {
                    // the section contents starts at the next line
                    $start_at[$node] = $i + 1;
                }
            }
            $end_at[$node] = \count($lines) + 1;
            foreach ($extra as $key => $value) {
                if (isset($end_at[$key])) {
                    $data = $this->mark_data($recipe, $this->parse(1, $offset, $value));
                    $updated_contents = $this->update_data_string(implode('', $nodes_lines[$key]), $data);
                    if (null === $updated_contents) {
                        // not an update: just add to section
                        array_splice($lines, $end_at[$key], 0, $data);
                        continue;
                    }
                    $original_end_at = $end_at[$key];
                    $length = $end_at[$key] - $start_at[$key];
                    array_splice($lines, $start_at[$key], $length, ltrim($updated_contents, "\n"));
                    // reset any start/end positions after this to the new positions
                    foreach ($start_at as $section_key => $at) {
                        if ($at > $original_end_at) {
                            $start_at[$section_key] = $at - $length - 1;
                        }
                    }
                    foreach ($end_at as $section_key => $at) {
                        if ($at > $original_end_at) {
                            $end_at[$section_key] = $at - $length;
                        }
                    }
                    continue;
                }
                $lines[] = \sprintf("\n%s:", $key);
                $lines[] = $this->mark_data($recipe, $this->parse(1, $offset, $value));
            }
            file_put_contents($docker_compose_file, implode('', $lines));
        }
    }
    private function get_contents_after_applying_recipe(string $root_dir, Recipe $recipe, array $config): array
    {
        if (0 === \count($config)) {
            return [];
        }
        $files = array_filter(array_map(fn($file) => $this->find_docker_compose_file($root_dir, $file), array_keys($config)));
        $original_contents = [];
        foreach ($files as $file) {
            $original_contents[$file] = file_exists($file) ? file_get_contents($file) : null;
        }
        $this->configure_docker_compose($recipe, $config, true);
        $updated_contents = [];
        foreach ($files as $file) {
            $local_path = $file;
            if (str_starts_with($file, $root_dir)) {
                $local_path = substr($file, \strlen($root_dir) + 1);
            }
            $local_path = ltrim($local_path, '/\\');
            $updated_contents[$local_path] = file_exists($file) ? file_get_contents($file) : null;
        }
        foreach ($original_contents as $file => $contents) {
            if (null === $contents) {
                if (file_exists($file)) {
                    unlink($file);
                }
            } else {
                file_put_contents($file, $contents);
            }
        }
        return $updated_contents;
    }
    private static function ask_docker_support(Io_Interface $io, Recipe $recipe): string
    {
        $warning = $io->is_interactive() ? 'WARNING' : 'IGNORING';
        $io->write_error(\sprintf('  - <warning> %s </> %s', $warning, $recipe->get_formatted_origin()));
        $question = '    The recipe for this package contains some Docker configuration.

    This may create/update <comment>compose.yaml</comment> or update <comment>Dockerfile</comment> (if it exists).

    Do you want to include Docker configuration from recipes?
    [<comment>y</>] Yes
    [<comment>n</>] No
    [<comment>p</>] Yes permanently, never ask again for this project
    [<comment>x</>] No permanently, never ask again for this project
    (defaults to <comment>y</>): ';
        return $io->ask_and_validate($question, function ($value): string {
            if (null === $value) {
                return 'y';
            }
            $value = strtolower((string) $value[0]);
            if (!\in_array($value, ['y', 'n', 'p', 'x'], true)) {
                throw new \InvalidArgumentException('Invalid choice.');
            }
            return $value;
        }, null, 'y');
    }
}