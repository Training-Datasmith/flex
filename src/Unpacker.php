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
use Composer\Config\Json_Config_Source;
use Composer\Factory;
use Composer\IO\Io_Interface;
use Composer\Json\Json_File;
use Composer\Json\Json_Manipulator;
use Composer\Package\Locker;
use Composer\Package\Version\Version_Selector;
use Composer\Repository\Composite_Repository;
use Composer\Repository\Repository_Set;
use Composer\Semver\Version_Parser;
use Symfony\Flex\Unpack\Operation;
use Symfony\Flex\Unpack\Result;
class Unpacker
{
    private readonly \Composer\Semver\Version_Parser $version_parser;
    public function __construct(private readonly Composer $composer, private readonly Package_Resolver $resolver)
    {
        $this->version_parser = new Version_Parser();
    }
    public function unpack(Operation $op, ?Result $result = null, array &$links = [], bool $dev_require = false): Result
    {
        if (null === $result) {
            $result = new Result();
        }
        $local_repo = $this->composer->get_repository_manager()->get_local_repository();
        foreach ($op->get_packages() as $package) {
            $pkg = $local_repo->find_package($package['name'], '*');
            $pkg ??= $this->composer->get_repository_manager()->find_package($package['name'], $package['version'] ?: '*');
            // not unpackable or no --unpack flag or empty packs (markers)
            if (null === $pkg || 'symfony-pack' !== $pkg->get_type() || !$op->should_unpack() || 0 === \count($pkg->get_requires()) + \count($pkg->get_dev_requires())) {
                $result->add_required($package['name'] . ($package['version'] ? ':' . $package['version'] : ''));
                continue;
            }
            if (!$result->add_unpacked($pkg)) {
                continue;
            }
            $requires = [];
            foreach ($pkg->get_requires() as $link) {
                $requires[$link->get_target()] = $link;
            }
            $dev_requires = $pkg->get_dev_requires();
            foreach ($dev_requires as $i => $link) {
                if (!isset($requires[$link->get_target()])) {
                    throw new \RuntimeException(\sprintf('Symfony pack "%s" must duplicate all entries from "require-dev" into "require" but entry "%s" was not found.', $package['name'], $link->get_target()));
                }
                $dev_requires[$i] = $requires[$link->get_target()];
                unset($requires[$link->get_target()]);
            }
            $version_selector = null;
            foreach ([$requires, $dev_requires] as $dev => $requires) {
                $dev = ($dev ?: $dev_require) ?: $package['dev'];
                foreach ($requires as $link) {
                    if ('php' === $link_name = $link->get_target()) {
                        continue;
                    }
                    $constraint = $link->get_pretty_constraint();
                    $constraint = substr($this->resolver->parse_version($link_name, $constraint, true), 1) ?: $constraint;
                    if ($sub_pkg = $local_repo->find_package($link_name, '*')) {
                        if ('symfony-pack' === $sub_pkg->get_type()) {
                            $sub_op = new Operation(true, $op->should_sort());
                            $sub_op->add_package($sub_pkg->get_name(), $constraint, $dev);
                            $result = $this->unpack($sub_op, $result, $links, $dev);
                            continue;
                        }
                        if ('*' === $constraint) {
                            if (null === $version_selector) {
                                $pool = new Repository_Set($this->composer->get_package()->get_minimum_stability(), $this->composer->get_package()->get_stability_flags());
                                $pool->add_repository(new Composite_Repository($this->composer->get_repository_manager()->get_repositories()));
                                $version_selector = new Version_Selector($pool);
                            }
                            $constraint = $version_selector->find_recommended_require_version($sub_pkg);
                        }
                    }
                    $link_type = $dev ? 'require-dev' : 'require';
                    $constraint = $this->version_parser->parse_constraints($constraint);
                    if (isset($links[$link_name])) {
                        $links[$link_name]['constraints'][] = $constraint;
                        if ('require' === $link_type) {
                            $links[$link_name]['type'] = 'require';
                        }
                    } else {
                        $links[$link_name] = ['type' => $link_type, 'name' => $link_name, 'constraints' => [$constraint]];
                    }
                }
            }
        }
        if (1 < \func_num_args()) {
            return $result;
        }
        $json_path = Factory::get_composer_file();
        $json_content = file_get_contents($json_path);
        $json_stored = json_decode($json_content, true);
        $json_manipulator = new Json_Manipulator($json_content);
        foreach ($result->get_unpacked() as $pkg) {
            $local_repo->remove_package($pkg);
            $local_repo->set_dev_package_names(array_diff($local_repo->get_dev_package_names(), [$pkg->get_name()]));
            $json_manipulator->remove_sub_node('require', $pkg->get_name());
            $json_manipulator->remove_sub_node('require-dev', $pkg->get_name());
        }
        foreach ($links as $link) {
            // nothing to do, package is already present in the "require" section
            if (isset($json_stored['require'][$link['name']])) {
                continue;
            }
            if (isset($json_stored['require-dev'][$link['name']])) {
                // nothing to do, package is already present in the "require-dev" section
                if ('require-dev' === $link['type']) {
                    continue;
                }
                // removes package from "require-dev", because it will be moved to "require"
                // save stored constraint
                $link['constraints'][] = $this->version_parser->parse_constraints($json_stored['require-dev'][$link['name']]);
                $json_manipulator->remove_sub_node('require-dev', $link['name']);
            }
            $constraint = end($link['constraints']);
            if (!$json_manipulator->add_link($link['type'], $link['name'], $constraint->get_pretty_string(), $op->should_sort())) {
                throw new \RuntimeException(\sprintf('Unable to unpack package "%s".', $link['name']));
            }
        }
        file_put_contents($json_path, $json_manipulator->get_contents());
        return $result;
    }
    public function update_lock(Result $result, Io_Interface $io): void
    {
        $json = new Json_File(Factory::get_composer_file());
        $manipulator = new Json_Config_Source($json);
        $locker = $this->composer->get_locker();
        $lock_data = $locker->get_lock_data();
        foreach ($result->get_unpacked() as $package) {
            $manipulator->remove_link('require-dev', $package->get_name());
            foreach ($lock_data['packages-dev'] as $i => $pkg) {
                if ($package->get_name() === $pkg['name']) {
                    unset($lock_data['packages-dev'][$i]);
                }
            }
            $manipulator->remove_link('require', $package->get_name());
            foreach ($lock_data['packages'] as $i => $pkg) {
                if ($package->get_name() === $pkg['name']) {
                    unset($lock_data['packages'][$i]);
                }
            }
        }
        $json_content = file_get_contents($json->get_path());
        $lock_data['packages'] = array_values($lock_data['packages']);
        $lock_data['packages-dev'] = array_values($lock_data['packages-dev']);
        $lock_data['content-hash'] = Locker::get_content_hash($json_content);
        $lock_file = new Json_File(substr($json->get_path(), 0, -4) . 'lock', null, $io);
        $lock_file->write($lock_data);
        $locker = new Locker($io, $lock_file, $this->composer->get_installation_manager(), $json_content);
        $this->composer->set_locker($locker);
        $local_repo = $this->composer->get_repository_manager()->get_local_repository();
        $local_repo->write($local_repo->get_dev_mode() ?? true, $this->composer->get_installation_manager());
    }
}