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
use Composer\Package\Alias_Package;
use Composer\Package\Package_Interface;
use Composer\Package\Root_Package_Interface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Composer\Semver\Version_Parser;
/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Package_Filter
{
    private $versions;
    private readonly \Composer\Semver\Version_Parser $version_parser;
    private $symfony_constraints;
    private $io;
    public function __construct(Io_Interface $io, private readonly string $symfony_require, private Downloader $downloader, private readonly bool $ignore_preleases = false)
    {
        $this->version_parser = new Version_Parser();
        $this->symfony_constraints = '' !== $this->symfony_require ? $this->version_parser->parse_constraints($this->symfony_require) : null;
        $this->io = $io;
    }
    /**
     * @param PackageInterface[] $data
     * @param PackageInterface[] $lockedPackages
     *
     * @return PackageInterface[]
     */
    public function remove_legacy_packages(array $data, Root_Package_Interface $root_package, array $locked_packages): array
    {
        if ($this->ignore_preleases) {
            $filtered_packages = [];
            foreach ($data as $package) {
                if (\in_array($package->get_stability(), ['stable', 'dev'], true)) {
                    $filtered_packages[] = $package;
                }
            }
            $data = $filtered_packages;
        }
        if (!$this->symfony_constraints || !$data) {
            return $data;
        }
        $locked_versions = [];
        foreach ($locked_packages as $package) {
            $locked_versions[$package->get_name()] = [$package->get_version()];
            if ($package instanceof Alias_Package) {
                $locked_versions[$package->get_name()][] = $package->get_alias_of()->get_version();
            }
        }
        $root_constraints = [];
        foreach ($root_package->get_requires() + $root_package->get_dev_requires() as $name => $link) {
            $root_constraints[$name] = $link->get_constraint();
        }
        $known_versions = null;
        $filtered_packages = [];
        $symfony_packages = [];
        $one_symfony = false;
        foreach ($data as $package) {
            $name = $package->get_name();
            $versions = [$package->get_version()];
            if ($package instanceof Alias_Package) {
                $versions[] = $package->get_alias_of()->get_version();
            }
            if ('symfony/symfony' !== $name && (array_intersect($versions, $locked_versions[$name] ?? []) || ($known_versions ??= $this->get_versions()) && !isset($known_versions['splits'][$name]) || isset($root_constraints[$name]) && !Intervals::have_intersections($this->symfony_constraints, $root_constraints[$name]) || 'symfony/psr-http-message-bridge' === $name && 6.4 > $versions[0])) {
                $filtered_packages[] = $package;
                continue;
            }
            if (null !== $alias = $package->get_extra()['branch-alias'][$package->get_version()] ?? null) {
                $versions[] = $this->version_parser->normalize($alias);
            }
            foreach ($versions as $version) {
                if ($this->symfony_constraints->matches(new Constraint('==', $version))) {
                    $filtered_packages[] = $package;
                    $one_symfony = $one_symfony || 'symfony/symfony' === $name;
                    continue 2;
                }
            }
            if ('symfony/symfony' === $name) {
                $symfony_packages[] = $package;
            } elseif (null !== $this->io) {
                $this->io->write_error(\sprintf('<info>Restricting packages listed in "symfony/symfony" to "%s"</>', $this->symfony_require));
                $this->io = null;
            }
        }
        if ($symfony_packages && !$one_symfony) {
            return array_merge($filtered_packages, $symfony_packages);
        }
        return $filtered_packages;
    }
    private function get_versions(): array
    {
        if (null !== $this->versions) {
            return $this->versions;
        }
        $versions = $this->downloader->get_versions();
        $this->downloader = null;
        $ok_versions = [];
        if (!isset($versions['splits'])) {
            throw new \LogicException('The Flex index is missing a "splits" entry. Did you forget to add "flex://defaults" in the "extra.symfony.endpoint" array of your composer.json?');
        }
        foreach ($versions['splits'] as $name => $vers) {
            foreach ($vers as $i => $v) {
                if (!isset($ok_versions[$v])) {
                    $ok_versions[$v] = false;
                    $w = str_ends_with((string) $v, '.x') ? $versions['next'] : $v;
                    for ($j = 0; $j < 60; ++$j) {
                        if ($this->symfony_constraints->matches(new Constraint('==', $w . '.' . $j . '.0'))) {
                            $ok_versions[$v] = true;
                            break;
                        }
                    }
                }
                if (!$ok_versions[$v]) {
                    unset($vers[$i]);
                }
            }
            if (!$vers || $vers === $versions['splits'][$name]) {
                unset($versions['splits'][$name]);
            }
        }
        return $this->versions = $versions;
    }
}