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
use Composer\Json\Json_File;
use Composer\Json\Json_Manipulator;
use Composer\Semver\Semver;
use Composer\Semver\Version_Parser;
use Seld\Json_Lint\Parsing_Exception;
/**
 * Synchronize package.json files detected in installed PHP packages with
 * the current application.
 */
class Package_Json_Synchronizer
{
    private $io;
    private readonly \Composer\Semver\Version_Parser $version_parser;
    public function __construct(private readonly string $root_dir, private readonly string $vendor_dir, private readonly Script_Executor $script_executor, Io_Interface $io)
    {
        $this->io = $io;
        $this->version_parser = new Version_Parser();
    }
    public function should_synchronize(): bool
    {
        return $this->root_dir && (file_exists($this->root_dir . '/package.json') || file_exists($this->root_dir . '/importmap.php'));
    }
    public function synchronize(array $php_packages): bool
    {
        if (file_exists($this->root_dir . '/importmap.php')) {
            $this->synchronize_for_asset_mapper($php_packages);
            return false;
        }
        try {
            Json_File::parse_json(file_get_contents($this->root_dir . '/package.json'));
        } catch (Parsing_Exception) {
            // if package.json is invalid (possible during a recipe upgrade), we can't update the file
            return false;
        }
        $did_change_package_json = $this->remove_obsolete_package_json_links();
        $dependencies = [];
        $php_packages = $this->normalize_php_packages($php_packages);
        foreach ($php_packages as $php_package) {
            foreach ($this->resolve_package_json_dependencies($php_package) as $dependency => $constraint) {
                $dependencies[$dependency][$php_package['name']] = $constraint;
            }
        }
        $did_change_package_json = $this->register_dependencies_in_package_json($dependencies) || $did_change_package_json;
        // Register controllers and entrypoints in controllers.json
        $this->update_controllers_json_file($php_packages);
        return $did_change_package_json;
    }
    private function synchronize_for_asset_mapper(array $php_packages): void
    {
        $import_map_entries = [];
        $php_packages = $this->normalize_php_packages($php_packages);
        foreach ($php_packages as $php_package) {
            foreach ($this->resolve_import_map_packages($php_package) as $name => $dependency_config) {
                $import_map_entries[$name] = $dependency_config;
            }
        }
        $this->update_import_map($import_map_entries);
        $this->update_controllers_json_file($php_packages);
    }
    private function remove_obsolete_package_json_links(): bool
    {
        $did_change_package_json = false;
        $manipulator = new Json_Manipulator(file_get_contents($this->root_dir . '/package.json'));
        $content = json_decode($manipulator->get_contents(), true);
        $js_dependencies = $content['dependencies'] ?? [];
        $js_dev_dependencies = $content['devDependencies'] ?? [];
        foreach (['dependencies' => $js_dependencies, 'devDependencies' => $js_dev_dependencies] as $key => $packages) {
            foreach ($packages as $name => $version) {
                if ('@' !== $name[0]) {
                    continue;
                }
                if (!str_starts_with((string) $version, 'file:' . $this->vendor_dir . '/')) {
                    continue;
                }
                if (!str_contains((string) $version, '/assets')) {
                    continue;
                }
                if (file_exists($this->root_dir . '/' . substr((string) $version, 5) . '/package.json')) {
                    continue;
                }
                $manipulator->remove_sub_node($key, $name);
                $did_change_package_json = true;
            }
        }
        file_put_contents($this->root_dir . '/package.json', $manipulator->get_contents());
        return $did_change_package_json;
    }
    private function resolve_package_json_dependencies(array $php_package): array
    {
        $dependencies = [];
        if (!$package_json = $this->resolve_package_json($php_package)) {
            return $dependencies;
        }
        if ($package_json->read()['symfony']['needsPackageAsADependency'] ?? true) {
            $dependencies['@' . $php_package['name']] = 'file:' . substr($package_json->get_path(), 1 + \strlen($this->root_dir), -13);
        }
        foreach ($package_json->read()['peerDependencies'] ?? [] as $peer_dependency => $constraint) {
            $dependencies[$peer_dependency] = $constraint;
        }
        return $dependencies;
    }
    private function resolve_import_map_packages($php_package): array
    {
        if (!$package_json = $this->resolve_package_json($php_package)) {
            return [];
        }
        $dependencies = [];
        foreach ($package_json->read()['symfony']['importmap'] ?? [] as $import_map_name => $constraint_config) {
            if (\is_string($constraint_config)) {
                // Matches string constraint, like "^3.0" or "path:%PACKAGE%/script.js"
                $constraint = $constraint_config;
                $package = $import_map_name;
                $entrypoint = false;
            } elseif (\is_array($constraint_config)) {
                // Matches array constraint, like {"version":"^3.0"} or {"version":"path:%PACKAGE%/script.js","entrypoint":true}
                // Note that non-path assets can't be entrypoint
                $constraint = $constraint_config['version'] ?? '';
                $package = $constraint_config['package'] ?? $import_map_name;
                $entrypoint = $constraint_config['entrypoint'] ?? false;
            } else {
                throw new \InvalidArgumentException(\sprintf('Invalid constraint config for key "%s": "%s" given, array or string expected.', $import_map_name, var_export($constraint_config, true)));
            }
            // When "$constraintConfig" matches one of the following cases:
            // - "entrypoint:%PACKAGE%/script.js"
            // - {"version": "entrypoint:%PACKAGE%/script.js"}
            if (str_starts_with((string) $constraint, 'entrypoint:')) {
                $entrypoint = true;
                $constraint = substr_replace($constraint, 'path:', 0, \strlen('entrypoint:'));
            }
            if (str_starts_with((string) $constraint, 'path:')) {
                $path = substr((string) $constraint, 5);
                $path = str_replace('%PACKAGE%', \dirname($package_json->get_path()), $path);
                $dependencies[$import_map_name] = ['path' => $path, 'entrypoint' => $entrypoint];
                continue;
            }
            $dependencies[$import_map_name] = ['version' => $constraint, 'package' => $package];
        }
        return $dependencies;
    }
    private function register_dependencies_in_package_json(array $flex_dependencies): bool
    {
        $did_change_package_json = false;
        $manipulator = new Json_Manipulator(file_get_contents($this->root_dir . '/package.json'));
        $content = json_decode($manipulator->get_contents(), true);
        foreach ($flex_dependencies as $dependency => $constraints) {
            if (1 !== \count($constraints) && 1 !== \count(array_count_values($constraints))) {
                // If the flex packages have a colliding peer dependency, leave the resolution to the user
                continue;
            }
            $constraint = array_shift($constraints);
            $parent_node = isset($content['dependencies'][$dependency]) ? 'dependencies' : 'devDependencies';
            if (!isset($content[$parent_node][$dependency])) {
                $content['devDependencies'][$dependency] = $constraint;
                $did_change_package_json = true;
            } elseif ($constraint !== $content[$parent_node][$dependency]) {
                if ($this->should_update_constraint($content[$parent_node][$dependency], $constraint)) {
                    $content[$parent_node][$dependency] = $constraint;
                    $did_change_package_json = true;
                }
            }
        }
        if ($did_change_package_json) {
            if (isset($content['dependencies'])) {
                $manipulator->add_main_key('dependencies', $content['dependencies']);
            }
            if (isset($content['devDependencies'])) {
                $dev_dependencies = $content['devDependencies'];
                uksort($dev_dependencies, strnatcmp(...));
                $manipulator->add_main_key('devDependencies', $dev_dependencies);
            }
            $new_contents = $manipulator->get_contents();
            if ($new_contents === file_get_contents($this->root_dir . '/package.json')) {
                return false;
            }
            file_put_contents($this->root_dir . '/package.json', $manipulator->get_contents());
        }
        return $did_change_package_json;
    }
    private function should_update_constraint(string $existing_constraint, string $constraint): bool
    {
        try {
            $existing_constraint = $this->version_parser->parse_constraints($existing_constraint);
            $constraint = $this->version_parser->parse_constraints($constraint);
            return !$existing_constraint->matches($constraint);
        } catch (\UnexpectedValueException) {
            return true;
        }
    }
    /**
     * @param array<string, array{path?: string, package?: string, version?: string, entrypoint?: bool}> $importMapEntries
     */
    private function update_import_map(array $import_map_entries): void
    {
        if (!$import_map_entries) {
            return;
        }
        $import_map_data = include $this->root_dir . '/importmap.php';
        foreach ($import_map_entries as $name => $import_map_entry) {
            if (isset($import_map_data[$name])) {
                if (!isset($import_map_data[$name]['version'])) {
                    // AssetMapper 6.3
                    continue;
                }
                $version = $import_map_data[$name]['version'];
                $version_constraint = $import_map_entry['version'] ?? null;
                // if the version constraint is satisfied, skip - else, update the package
                if (Semver::satisfies($version, $version_constraint)) {
                    continue;
                }
                $this->io->write_error(\sprintf('Updating package <comment>%s</> from <info>%s</> to <info>%s</>.', $name, $version, $version_constraint));
            }
            if (isset($import_map_entry['path'])) {
                $arguments = [$name, '--path=' . $import_map_entry['path']];
                if (isset($import_map_entry['entrypoint']) && true === $import_map_entry['entrypoint']) {
                    $arguments[] = '--entrypoint';
                }
                $this->script_executor->execute('symfony-cmd', 'importmap:require', $arguments);
                continue;
            }
            if (isset($import_map_entry['version'])) {
                $package_name = $import_map_entry['package'] . '@' . $import_map_entry['version'];
                if ($import_map_entry['package'] !== $name) {
                    $package_name .= '=' . $name;
                }
                $arguments = [$package_name];
                $this->script_executor->execute('symfony-cmd', 'importmap:require', $arguments);
                continue;
            }
            throw new \InvalidArgumentException(\sprintf('Invalid importmap entry: "%s".', var_export($import_map_entry, true)));
        }
    }
    private function update_controllers_json_file(array $php_packages): void
    {
        if (!file_exists($controllers_json_path = $this->root_dir . '/assets/controllers.json')) {
            return;
        }
        try {
            $previous_controllers_json = (new Json_File($controllers_json_path))->read();
        } catch (Parsing_Exception) {
            // if controllers.json is invalid (possible during a recipe upgrade), we can't update the file
            return;
        }
        $new_controllers_json = ['controllers' => [], 'entrypoints' => $previous_controllers_json['entrypoints']];
        foreach ($php_packages as $php_package) {
            if (!$package_json = $this->resolve_package_json($php_package)) {
                continue;
            }
            $name = '@' . $php_package['name'];
            foreach ($package_json->read()['symfony']['controllers'] ?? [] as $controller_name => $default_config) {
                // If the package has just been added (no config), add the default config provided by the package
                if (!isset($previous_controllers_json['controllers'][$name][$controller_name])) {
                    $config = [];
                    $config['enabled'] = $default_config['enabled'];
                    $config['fetch'] = $default_config['fetch'] ?? 'eager';
                    if (isset($default_config['autoimport'])) {
                        $config['autoimport'] = $default_config['autoimport'];
                    }
                    $new_controllers_json['controllers'][$name][$controller_name] = $config;
                    continue;
                }
                // Otherwise, the package exists: merge new config with user config
                $previous_config = $previous_controllers_json['controllers'][$name][$controller_name];
                $config = [];
                $config['enabled'] = $previous_config['enabled'];
                $config['fetch'] = $previous_config['fetch'] ?? 'eager';
                if (isset($default_config['autoimport'])) {
                    $config['autoimport'] = [];
                    // Use for each autoimport either the previous config if one existed or the default config otherwise
                    foreach ($default_config['autoimport'] as $autoimport => $enabled) {
                        $config['autoimport'][$autoimport] = $previous_config['autoimport'][$autoimport] ?? $enabled;
                    }
                }
                $new_controllers_json['controllers'][$name][$controller_name] = $config;
            }
            foreach ($package_json->read()['symfony']['entrypoints'] ?? [] as $entrypoint => $filename) {
                if (!isset($new_controllers_json['entrypoints'][$entrypoint])) {
                    $new_controllers_json['entrypoints'][$entrypoint] = $filename;
                }
            }
        }
        file_put_contents($controllers_json_path, json_encode($new_controllers_json, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    }
    private function resolve_package_json(array $php_package): ?Json_File
    {
        $package_dir = $this->root_dir . '/' . $this->vendor_dir . '/' . $php_package['name'];
        if (!\in_array('symfony-ux', $php_package['keywords'] ?? [], true)) {
            return null;
        }
        foreach (['/assets', '/Resources/assets', '/src/Resources/assets'] as $subdir) {
            $package_json_path = $package_dir . $subdir . '/package.json';
            if (!file_exists($package_json_path)) {
                continue;
            }
            return new Json_File($package_json_path);
        }
        return null;
    }
    private function normalize_php_packages(array $php_packages): array
    {
        foreach ($php_packages as $k => $php_package) {
            if (\is_string($php_package)) {
                // support for smooth upgrades from older flex versions
                $php_packages[$k] = $php_package = ['name' => $php_package, 'keywords' => ['symfony-ux']];
            }
        }
        return $php_packages;
    }
}