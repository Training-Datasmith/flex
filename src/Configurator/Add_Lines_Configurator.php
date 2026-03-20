<?php

declare (strict_types=1);
namespace Symfony\Flex\Configurator;

use Composer\IO\Io_Interface;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Kevin Bond <kevinbond@gmail.com>
 * @author Ryan Weaver <ryan@symfonycasts.com>
 */
class Add_Lines_Configurator extends Abstract_Configurator
{
    private const POSITION_TOP = 'top';
    private const POSITION_BOTTOM = 'bottom';
    private const POSITION_AFTER_TARGET = 'after_target';
    private const VALID_POSITIONS = [self::POSITION_TOP, self::POSITION_BOTTOM, self::POSITION_AFTER_TARGET];
    /**
     * Holds file contents for files that have been loaded.
     * This allows us to "change" the contents of a file multiple
     * times before we actually write it out.
     *
     * @var string[]
     */
    private array $file_contents = [];
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = []): void
    {
        $this->file_contents = [];
        $this->execute_configure($recipe, $config);
        foreach ($this->file_contents as $file => $contents) {
            $this->write(\sprintf('[add-lines] Patching file "%s"', $this->relativize($file)));
            file_put_contents($file, $contents);
        }
    }
    public function unconfigure(Recipe $recipe, $config, Lock $lock): void
    {
        $this->file_contents = [];
        $this->execute_unconfigure($recipe, $config);
        foreach ($this->file_contents as $file => $change) {
            $this->write(\sprintf('[add-lines] Reverting file "%s"', $this->relativize($file)));
            file_put_contents($file, $change);
        }
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        // manually check for "requires", as unconfigure ignores it
        $original_config = array_filter($original_config, fn(array $item) => !isset($item['requires']) || $this->is_package_installed($item['requires']));
        // reset the file content cache
        $this->file_contents = [];
        $this->execute_unconfigure($recipe_update->get_original_recipe(), $original_config);
        $this->execute_configure($recipe_update->get_new_recipe(), $new_config);
        $new_files = [];
        $original_files = [];
        foreach ($this->file_contents as $file => $contents) {
            // set the original file to the current contents
            $original_files[$this->relativize($file)] = file_get_contents($file);
            // and the new file where the old recipe was unconfigured, and the new configured
            $new_files[$this->relativize($file)] = $contents;
        }
        $recipe_update->add_original_files($original_files);
        $recipe_update->add_new_files($new_files);
    }
    public function execute_configure(Recipe $recipe, $config): void
    {
        foreach ($config as $patch) {
            if (!isset($patch['file'])) {
                $this->write(\sprintf('The "file" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->get_name()));
                continue;
            }
            if (isset($patch['requires']) && !$this->is_package_installed($patch['requires'])) {
                continue;
            }
            if (!isset($patch['content'])) {
                $this->write(\sprintf('The "content" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->get_name()));
                continue;
            }
            $content = $patch['content'];
            $file = $this->path->concatenate([$this->options->get('root-dir'), $this->options->expand_target_dir($patch['file'])]);
            $warn_if_missing = isset($patch['warn_if_missing']) && $patch['warn_if_missing'];
            if (!is_file($file)) {
                $this->write([\sprintf('Could not add lines to file <info>%s</info> as it does not exist. Missing lines:', $patch['file']), '<comment>"""</comment>', $content, '<comment>"""</comment>', ''], $warn_if_missing ? Io_Interface::NORMAL : Io_Interface::VERBOSE);
                continue;
            }
            if (!isset($patch['position'])) {
                $this->write(\sprintf('The "position" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->get_name()));
                continue;
            }
            $position = $patch['position'];
            if (!\in_array($position, self::VALID_POSITIONS, true)) {
                $this->write(\sprintf('The "position" key must be one of "%s" for the "add-lines" configurator for recipe "%s". Skipping', implode('", "', self::VALID_POSITIONS), $recipe->get_name()));
                continue;
            }
            if (self::POSITION_AFTER_TARGET === $position && !isset($patch['target'])) {
                $this->write(\sprintf('The "target" key is required when "position" is "%s" for the "add-lines" configurator for recipe "%s". Skipping', self::POSITION_AFTER_TARGET, $recipe->get_name()));
                continue;
            }
            $target = $patch['target'] ?? null;
            $new_contents = $this->get_patched_contents($file, $content, $position, $target, $warn_if_missing);
            $this->file_contents[$file] = $new_contents;
        }
    }
    public function execute_unconfigure(Recipe $recipe, $config): void
    {
        foreach ($config as $patch) {
            if (!isset($patch['file'])) {
                $this->write(\sprintf('The "file" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->get_name()));
                continue;
            }
            // Ignore "requires": the target packages may have just become uninstalled.
            // Checking for a "content" match is enough.
            $file = $this->path->concatenate([$this->options->get('root-dir'), $this->options->expand_target_dir($patch['file'])]);
            if (!is_file($file)) {
                continue;
            }
            if (!isset($patch['content'])) {
                $this->write(\sprintf('The "content" key is required for the "add-lines" configurator for recipe "%s". Skipping', $recipe->get_name()));
                continue;
            }
            $value = $patch['content'];
            $new_contents = $this->get_un_patched_contents($file, $value);
            $this->file_contents[$file] = $new_contents;
        }
    }
    private function get_patched_contents(string $file, string $value, string $position, ?string $target, bool $warn_if_missing): string
    {
        $file_contents = $this->read_file($file);
        if (str_contains($file_contents, $value)) {
            return $file_contents;
            // already includes value, skip
        }
        switch ($position) {
            case self::POSITION_BOTTOM:
                $file_contents .= "\n" . $value;
                break;
            case self::POSITION_TOP:
                $file_contents = $value . "\n" . $file_contents;
                break;
            case self::POSITION_AFTER_TARGET:
                $lines = explode("\n", $file_contents);
                $target_found = false;
                foreach ($lines as $key => $line) {
                    if (str_contains($line, (string) $target)) {
                        array_splice($lines, $key + 1, 0, $value);
                        $target_found = true;
                        break;
                    }
                }
                $file_contents = implode("\n", $lines);
                if (!$target_found) {
                    $this->write([\sprintf('Could not add lines after "%s" as no such string was found in "%s". Missing lines:', $target, $file), '<comment>"""</comment>', $value, '<comment>"""</comment>', ''], $warn_if_missing ? Io_Interface::NORMAL : Io_Interface::VERBOSE);
                }
                break;
        }
        return $file_contents;
    }
    private function get_un_patched_contents(string $file, $value): string
    {
        $file_contents = $this->read_file($file);
        if (!str_contains($file_contents, (string) $value)) {
            return $file_contents;
            // value already gone!
        }
        if (str_contains($file_contents, "\n" . $value)) {
            $value = "\n" . $value;
        } elseif (str_contains($file_contents, $value . "\n")) {
            $value .= "\n";
        }
        $position = strpos($file_contents, (string) $value);
        return substr_replace($file_contents, '', $position, \strlen((string) $value));
    }
    private function is_package_installed($packages): bool
    {
        if (\is_string($packages)) {
            $packages = [$packages];
        }
        $installed_repo = $this->composer->get_repository_manager()->get_local_repository();
        foreach ($packages as $package) {
            $package = explode(':', (string) $package, 2);
            $package_name = $package[0];
            $constraint = $package[1] ?? '*';
            if (null === $installed_repo->find_package($package_name, $constraint)) {
                return false;
            }
        }
        return true;
    }
    private function relativize(string $path): string
    {
        $root_dir = $this->options->get('root-dir');
        if (str_starts_with($path, (string) $root_dir)) {
            $path = substr($path, \strlen((string) $root_dir) + 1);
        }
        return ltrim($path, '/\\');
    }
    private function read_file(string $file): string
    {
        if (isset($this->file_contents[$file])) {
            return $this->file_contents[$file];
        }
        $file_contents = file_get_contents($file);
        $this->file_contents[$file] = $file_contents;
        return $file_contents;
    }
}