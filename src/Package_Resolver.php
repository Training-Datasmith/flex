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

use Composer\Factory;
use Composer\Package\Version\Version_Parser;
use Composer\Repository\Platform_Repository;
use Composer\Semver\Constraint\Match_All_Constraint;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Package_Resolver
{
    private static array $SYMFONY_VERSIONS = ['lts', 'previous', 'stable', 'next', 'dev'];
    public function __construct(private readonly Downloader $downloader)
    {
    }
    public function resolve(array $arguments = [], bool $is_require = false): array
    {
        // first pass split on : and = to resolve package names
        $packages = [];
        foreach ($arguments as $i => $argument) {
            if (false !== ($pos = strpos((string) $argument, ':')) || false !== $pos = strpos((string) $argument, '=')) {
                $package = $this->resolve_package_name(substr((string) $argument, 0, $pos), $i, $is_require);
                $version = substr((string) $argument, $pos + 1);
                $packages[] = $package . ':' . $version;
            } else {
                $packages[] = $this->resolve_package_name($argument, $i, $is_require);
            }
        }
        // second pass to resolve versions
        $version_parser = new Version_Parser();
        $requires = [];
        $to_guess = [];
        foreach ($version_parser->parse_name_version_pairs($packages) as $package) {
            $version = $this->parse_version($package['name'], $package['version'] ?? '', $is_require);
            if ('' !== $version) {
                unset($to_guess[$package['name']]);
            } elseif (!isset($requires[$package['name']])) {
                $to_guess[$package['name']] = new Match_All_Constraint();
            }
            $requires[$package['name']] = $package['name'] . $version;
        }
        if ($to_guess && $is_require) {
            foreach ($this->downloader->get_symfony_packs($to_guess) as $package) {
                $requires[$package] .= ':*';
            }
        }
        return array_values($requires);
    }
    public function parse_version(string $package, string $version, bool $is_require): string
    {
        $guess = 'guess' === ($version ?: 'guess');
        if (!str_starts_with($package, 'symfony/')) {
            return $guess ? '' : ':' . $version;
        }
        $versions = $this->downloader->get_versions();
        if (!isset($versions['splits'][$package])) {
            return $guess ? '' : ':' . $version;
        }
        if ($guess || '*' === $version) {
            try {
                $config = @json_decode(file_get_contents(Factory::get_composer_file()), true);
            } finally {
                if (!$is_require || !isset($config['extra']['symfony']['require'])) {
                    return '';
                }
            }
            $version = $config['extra']['symfony']['require'];
        } elseif ('dev' === $version) {
            $version = '^' . $versions['dev-name'] . '@dev';
        } elseif ('next' === $version) {
            $version = '^' . $versions[$version] . '@dev';
        } elseif (\in_array($version, self::$SYMFONY_VERSIONS, true)) {
            $version = '^' . $versions[$version];
        }
        return ':' . $version;
    }
    private function resolve_package_name(string $argument, int $position, bool $is_require): string
    {
        $skipped_packages = ['mirrors', 'nothing', ''];
        if (!$is_require) {
            $skipped_packages[] = 'lock';
        }
        if (str_contains($argument, '/') || preg_match(Platform_Repository::PLATFORM_PACKAGE_REGEX, $argument) || preg_match('{(?<=[a-z0-9_/-])\*|\*(?=[a-z0-9_/-])}i', $argument) || \in_array($argument, $skipped_packages)) {
            return $argument;
        }
        $aliases = $this->downloader->get_aliases();
        if (isset($aliases[$argument])) {
            $argument = $aliases[$argument];
        } else {
            // is it a version or an alias that does not exist?
            try {
                $version_parser = new Version_Parser();
                $version_parser->parse_constraints($argument);
            } catch (\UnexpectedValueException) {
                // is it a special Symfony version?
                if (!\in_array($argument, self::$SYMFONY_VERSIONS, true)) {
                    $this->throw_alternatives($argument, $position);
                }
            }
        }
        return $argument;
    }
    /**
     * @throws \UnexpectedValueException
     */
    private function throw_alternatives(string $argument, int $position): void
    {
        $alternatives = [];
        foreach ($this->downloader->get_aliases() as $alias => $package) {
            $lev = levenshtein($argument, $alias);
            if ($lev <= \strlen($argument) / 3 || '' !== $argument && str_contains($alias, $argument)) {
                $alternatives[$package][] = $alias;
            }
        }
        // First position can only be a package name, not a version
        if ($alternatives || 0 === $position) {
            $message = \sprintf('"%s" is not a valid alias.', $argument);
            if ($alternatives) {
                if (1 === \count($alternatives)) {
                    $message .= " Did you mean this:\n";
                } else {
                    $message .= " Did you mean one of these:\n";
                }
                foreach ($alternatives as $package => $aliases) {
                    $message .= \sprintf("  \"%s\", supported aliases: \"%s\"\n", $package, implode('", "', $aliases));
                }
            }
        } else {
            $message = \sprintf('Could not parse version constraint "%s".', $argument);
        }
        throw new \UnexpectedValueException($message);
    }
}