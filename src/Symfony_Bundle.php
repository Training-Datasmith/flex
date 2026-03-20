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

use Composer\Composer;
use Composer\Package\Package_Interface;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Symfony_Bundle
{
    private readonly string $vendor_dir;
    public function __construct(Composer $composer, private readonly Package_Interface $package, private readonly string $operation)
    {
        $this->vendor_dir = rtrim((string) $composer->get_config()->get('vendor-dir'), '/');
    }
    public function get_class_names(): array
    {
        $uninstall = 'uninstall' === $this->operation;
        $classes = [];
        $autoload = $this->package->get_autoload();
        $is_sylius_plugin = 'sylius-plugin' === $this->package->get_type();
        foreach (['psr-4' => true, 'psr-0' => false] as $psr => $is_psr4) {
            if (!isset($autoload[$psr])) {
                continue;
            }
            foreach ($autoload[$psr] as $namespace => $paths) {
                if (!\is_array($paths)) {
                    $paths = [$paths];
                }
                foreach ($paths as $path) {
                    foreach ($this->extract_class_names($namespace, $is_sylius_plugin) as $class) {
                        // we only check class existence on install as we do have the code available
                        // in contrast to uninstall operation
                        if (!$uninstall && !$this->is_bundle_class($class, $path, $is_psr4)) {
                            continue;
                        }
                        $classes[] = $class;
                    }
                }
            }
        }
        return $classes;
    }
    private function extract_class_names(string $namespace, bool $is_sylius_plugin): array
    {
        $namespace = trim($namespace, '\\');
        $class = $namespace . '\\';
        $parts = explode('\\', $namespace);
        $suffix = $parts[\count($parts) - 1];
        $end_of_word = substr($suffix, -6);
        if ($is_sylius_plugin) {
            if ('Bundle' !== $end_of_word && 'Plugin' !== $end_of_word) {
                $suffix .= 'Bundle';
            }
        } elseif ('Bundle' !== $end_of_word) {
            $suffix .= 'Bundle';
        }
        $classes = [$class . $suffix];
        $acc = '';
        foreach (\array_slice($parts, 0, -1) as $part) {
            if ('Bundle' === $part) {
                continue;
            }
            if ($is_sylius_plugin && 'Plugin' === $part) {
                continue;
            }
            $classes[] = $class . $part . $suffix;
            $acc .= $part;
            $classes[] = $class . $acc . $suffix;
        }
        return array_unique($classes);
    }
    private function is_bundle_class(string $class, string $path, bool $is_psr4): bool
    {
        $class_path = ($this->vendor_dir ? $this->vendor_dir . '/' : '') . $this->package->get_pretty_name() . '/' . $path . '/';
        $parts = explode('\\', $class);
        $class = $parts[\count($parts) - 1];
        if (!$is_psr4) {
            $class_path .= str_replace('\\', '', implode('/', \array_slice($parts, 0, -1))) . '/';
        }
        $class_path .= str_replace('\\', '/', $class) . '.php';
        if (!file_exists($class_path)) {
            return false;
        }
        // heuristic that should work in almost all cases
        $class_contents = file_get_contents($class_path);
        return str_contains($class_contents, 'Symfony\Component\HttpKernel\Bundle\Bundle') || str_contains($class_contents, 'Symfony\Component\HttpKernel\Bundle\AbstractBundle');
    }
}