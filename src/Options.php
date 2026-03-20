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
namespace Symfony\Flex;

use Composer\IO\Io_Interface;
use Composer\Util\Process_Executor;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Options
{
    private array $written_files = [];
    private array $lock_data;
    public function __construct(private array $options = [], private readonly ?Io_Interface $io = null, ?Lock $lock = null)
    {
        $this->lock_data = $lock?->all() ?? [];
    }
    public function get(string $name)
    {
        return $this->options[$name] ?? null;
    }
    public function expand_target_dir(string $target): string
    {
        $result = preg_replace_callback('{%(.+?)%}', function ($matches): string {
            $option = str_replace('_', '-', strtolower((string) $matches[1]));
            if (!isset($this->options[$option])) {
                return $matches[0];
            }
            return rtrim((string) $this->options[$option], '/');
        }, $target);
        $phpunit_dist_files = ['phpunit.xml.dist' => true, 'phpunit.dist.xml' => true];
        $root_dir = $this->get('root-dir');
        if (null === $root_dir || !isset($phpunit_dist_files[$result]) || !is_dir($root_dir) || file_exists($root_dir . '/' . $result)) {
            return $result;
        }
        unset($phpunit_dist_files[$result]);
        $other_phpunit_dist_file = key($phpunit_dist_files);
        return file_exists($root_dir . '/' . $other_phpunit_dist_file) ? $other_phpunit_dist_file : $result;
    }
    public function should_write_file(string $file, bool $overwrite, bool $skip_question): bool
    {
        if (isset($this->written_files[$file])) {
            return false;
        }
        $this->written_files[$file] = true;
        if (!file_exists($file)) {
            return true;
        }
        if (!$overwrite) {
            return false;
        }
        if (!filesize($file)) {
            return true;
        }
        if ($skip_question) {
            return true;
        }
        exec('git status --short --ignored --untracked-files=all -- ' . Process_Executor::escape($file) . ' 2>&1', $output, $status);
        if (0 !== $status) {
            return $this->io && $this->io->ask_confirmation(\sprintf('Cannot determine the state of the "%s" file, overwrite anyway? [y/N] ', $file), false);
        }
        if (empty($output[0]) || preg_match('/^[ AMDRCU][ D][ \t]/', $output[0])) {
            return true;
        }
        $name = basename($file);
        $name = \strlen($output[0]) - \strlen($name) === strrpos($output[0], $name) ? substr($output[0], 3) : $name;
        return $this->io && $this->io->ask_confirmation(\sprintf('File "%s" has uncommitted changes, overwrite? [y/N] ', $name), false);
    }
    public function get_removable_files(Recipe $recipe, Lock $lock): array
    {
        if (null === $removable_files = $this->lock_data[$recipe->get_name()]['files'] ?? null) {
            $removable_files = [];
            foreach (array_keys($recipe->get_files()) as $source => $target) {
                if (str_ends_with($source, '/')) {
                    $removable_files[] = $this->expand_target_dir($target);
                }
            }
        }
        unset($this->lock_data[$recipe->get_name()]);
        $locked_files = array_count_values(array_merge(...array_column($lock->all(), 'files')));
        $non_removable_files = [];
        foreach ($removable_files as $i => $file) {
            if (isset($locked_files[$file])) {
                $non_removable_files[] = $file;
                unset($removable_files[$i]);
            }
        }
        if ($non_removable_files && $this->io) {
            $this->io?->write_error('    <warning>The following files are still referenced by other recipes, you might need to adjust them manually:</warning>');
            foreach ($non_removable_files as $file) {
                $this->io?->write_error('      - ' . $file);
            }
        }
        return array_values($removable_files);
    }
    public function to_array(): array
    {
        return $this->options;
    }
}