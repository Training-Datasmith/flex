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
namespace Symfony\Flex\Update;

use Composer\IO\Io_Interface;
use Composer\Util\Process_Executor;
use Symfony\Component\Filesystem\Exception\Io_Exception;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Flex\Lock;
class Recipe_Patcher
{
    private readonly \Symfony\Component\Filesystem\Filesystem $filesystem;
    private $io;
    private $process_executor;
    public function __construct(private readonly string $root_dir, Io_Interface $io, private readonly Lock $symfony_lock)
    {
        $this->filesystem = new Filesystem();
        $this->io = $io;
        $this->process_executor = new Process_Executor($io);
    }
    /**
     * Applies the patch. If it fails unexpectedly, an exception will be thrown.
     *
     * @return bool returns true if fully successful, false if conflicts were encountered
     */
    public function apply_patch(Recipe_Patch $patch, ?string $package_name = null): bool
    {
        $with_conflicts = $this->_apply_patch_file($patch);
        $locked_files = $package_name ? array_count_values(array_merge(...array_column(array_filter($this->symfony_lock->all(), fn($package): bool => $package !== $package_name, \ARRAY_FILTER_USE_KEY), 'files'))) : [];
        $non_removable_files = [];
        foreach ($patch->get_deleted_files() as $deleted_file) {
            if (!file_exists($this->root_dir . '/' . $deleted_file)) {
                continue;
            }
            if (isset($locked_files[$deleted_file])) {
                $non_removable_files[] = $deleted_file;
                continue;
            }
            $this->execute(\sprintf('git rm %s', Process_Executor::escape($deleted_file)), $this->root_dir);
        }
        if ($non_removable_files) {
            $this->io->write_error('  <warning>The following files were removed in the recipe, but are still referenced by other recipes. You might need to adjust them manually:</warning>');
            foreach ($non_removable_files as $file) {
                $this->io->write_error('      - ' . $file);
            }
            $this->io->write_error('');
        }
        return $with_conflicts;
    }
    public function generate_patch(array $original_files, array $new_files): Recipe_Patch
    {
        $ignored_files = $this->get_ignored_files(array_keys($original_files) + array_keys($new_files));
        // null implies "file does not exist"
        $original_files = array_filter($original_files, fn($file, $file_name) => null !== $file && !\in_array($file_name, $ignored_files), \ARRAY_FILTER_USE_BOTH);
        $new_files = array_filter($new_files, fn($file, $file_name) => null !== $file && !\in_array($file_name, $ignored_files), \ARRAY_FILTER_USE_BOTH);
        $deleted_files = [];
        // find removed files & record that they are deleted
        // unset them from originalFiles to avoid unnecessary blobs being added
        foreach ($original_files as $file => $contents) {
            if (!isset($new_files[$file])) {
                $deleted_files[] = $file;
                unset($original_files[$file]);
            }
        }
        // If a file is being modified, but does not exist in the current project,
        // it cannot be patched. We generate the diff for these, but then remove
        // it from the patch (and optionally report this diff to the user).
        $modified_files = array_intersect_key(array_keys($original_files), array_keys($new_files));
        $deleted_modified_files = [];
        foreach ($modified_files as $modified_file) {
            if (!file_exists($this->root_dir . '/' . $modified_file) && $original_files[$modified_file] !== $new_files[$modified_file]) {
                $deleted_modified_files[] = $modified_file;
            }
        }
        // Use git binary to get project path from repository root
        $prefix = trim($this->execute('git rev-parse --show-prefix', $this->root_dir));
        $tmp_path = sys_get_temp_dir() . '/_flex_recipe_update' . uniqid(mt_rand(), true);
        $this->filesystem->mkdir($tmp_path);
        try {
            $this->execute('git init', $tmp_path);
            $this->execute('git config commit.gpgsign false', $tmp_path);
            $this->execute('git config user.name "Flex Updater"', $tmp_path);
            $this->execute('git config user.email ""', $tmp_path);
            $blobs = [];
            if (\count($original_files) > 0) {
                $this->write_files($original_files, $tmp_path);
                $this->execute('git add -A', $tmp_path);
                $this->execute('git commit -n -m "original files"', $tmp_path);
                $blobs = $this->generate_blobs($original_files, $tmp_path);
            }
            $this->write_files($new_files, $tmp_path);
            $this->execute('git add -A', $tmp_path);
            $patch_string = $this->execute(\sprintf('git diff --cached --src-prefix "a/%s" --dst-prefix "b/%s"', $prefix, $prefix), $tmp_path);
            $removed_patches = [];
            $patch_string = Diff_Helper::remove_files_from_patch($patch_string, $deleted_modified_files, $removed_patches);
            return new Recipe_Patch($patch_string, $blobs, $deleted_files, $removed_patches);
        } finally {
            try {
                $this->filesystem->remove($tmp_path);
            } catch (Io_Exception) {
                // this can sometimes fail due to git file permissions
                // if that happens, just leave it: we're in the temp directory anyways
            }
        }
    }
    private function write_files(array $files, string $directory): void
    {
        foreach ($files as $filename => $contents) {
            $path = $directory . '/' . $filename;
            if (null === $contents) {
                if (file_exists($path)) {
                    unlink($path);
                }
                continue;
            }
            if (!file_exists(\dirname($path))) {
                $this->filesystem->mkdir(\dirname($path));
            }
            file_put_contents($path, $contents);
        }
    }
    private function execute(string $command, string $cwd): string
    {
        $output = '';
        $status_code = $this->process_executor->execute($command, $output, $cwd);
        if (0 !== $status_code) {
            throw new \LogicException(\sprintf('Command "%s" failed: "%s". Output: "%s".', $command, $this->process_executor->get_error_output(), $output));
        }
        return $output;
    }
    /**
     * Adds git blobs for each original file.
     *
     * For patching to work, each original file & contents needs to be
     * available to git as a blob. This is because the patch contains
     * the ref to the original blob, and git uses that to find the
     * original file (which is needed for the 3-way merge).
     */
    private function add_missing_blobs(array $blobs): array
    {
        $added_blobs = [];
        foreach ($blobs as $hash => $contents) {
            $blob_path = $this->get_blob_path($this->root_dir, $hash);
            if (file_exists($blob_path)) {
                continue;
            }
            $added_blobs[] = $blob_path;
            if (!file_exists(\dirname($blob_path))) {
                $this->filesystem->mkdir(\dirname($blob_path));
            }
            file_put_contents($blob_path, $contents);
        }
        return $added_blobs;
    }
    private function generate_blobs(array $original_files, string $original_files_root): array
    {
        $added_blobs = [];
        foreach ($original_files as $filename => $contents) {
            // if the file didn't originally exist, no blob needed
            if (!file_exists($original_files_root . '/' . $filename)) {
                continue;
            }
            $hash = trim($this->execute('git hash-object ' . Process_Executor::escape($filename), $original_files_root));
            $added_blobs[$hash] = file_get_contents($this->get_blob_path($original_files_root, $hash));
        }
        return $added_blobs;
    }
    private function get_blob_path(string $git_root, string $hash): string
    {
        $git_dir = trim($this->execute('git rev-parse --absolute-git-dir', $git_root));
        $hash_start = substr($hash, 0, 2);
        $hash_end = substr($hash, 2);
        return $git_dir . '/objects/' . $hash_start . '/' . $hash_end;
    }
    private function _apply_patch_file(Recipe_Patch $patch): bool
    {
        if (!$patch->get_patch()) {
            // nothing to do!
            return true;
        }
        $added_blobs = $this->add_missing_blobs($patch->get_blobs());
        $patch_path = $this->root_dir . '/_flex_recipe_update.patch';
        file_put_contents($patch_path, $patch->get_patch());
        try {
            $this->execute('git update-index --refresh', $this->root_dir);
            $output = '';
            $status_code = $this->process_executor->execute('git apply "_flex_recipe_update.patch" -3', $output, $this->root_dir);
            if (0 === $status_code) {
                // successful with no conflicts
                return true;
            }
            if (str_contains((string) $this->process_executor->get_error_output(), 'with conflicts')) {
                // successful with conflicts
                return false;
            }
            throw new \LogicException('Error applying the patch: ' . $this->process_executor->get_error_output());
        } finally {
            unlink($patch_path);
            // clean up any temporary blobs
            foreach ($added_blobs as $filename) {
                unlink($filename);
            }
        }
    }
    private function get_ignored_files(array $file_names): array
    {
        $args = implode(' ', array_map([Process_Executor::class, 'escape'], $file_names));
        $output = '';
        $this->process_executor->execute(\sprintf('git check-ignore %s', $args), $output, $this->root_dir);
        return $this->process_executor->split_lines($output);
    }
}