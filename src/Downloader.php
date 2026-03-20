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

use Composer\Cache;
use Composer\Composer;
use Composer\Dependency_Resolver\Operation\Operation_Interface;
use Composer\Dependency_Resolver\Operation\Uninstall_Operation;
use Composer\Dependency_Resolver\Operation\Update_Operation;
use Composer\IO\Io_Interface;
use Composer\Json\Json_File;
use Composer\Package\Base_Package;
use Composer\Util\Http\Response as ComposerResponse;
use Composer\Util\Http_Downloader;
use Composer\Util\Loop;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Nicolas Grekas <p@tchwork.com>
 */
class Downloader
{
    private const DEFAULT_ENDPOINTS = ['https://raw.githubusercontent.com/symfony/recipes/flex/main/index.json', 'https://raw.githubusercontent.com/symfony/recipes-contrib/flex/main/index.json'];
    private const MAX_LENGTH = 1000;
    private static $versions;
    private static $aliases;
    private readonly string $sess;
    private readonly \Composer\Cache $cache;
    private bool $degraded_mode = false;
    private ?array $endpoints;
    private ?array $index = null;
    private ?array $conflicts = null;
    private ?string $legacy_endpoint;
    private string|bool|null $ca_file = null;
    private bool $enabled = true;
    private readonly \Composer\Composer $composer;
    public function __construct(Composer $composer, private readonly Io_Interface $io, private readonly Http_Downloader $rfs)
    {
        if (getenv('SYMFONY_CAFILE')) {
            $this->ca_file = getenv('SYMFONY_CAFILE');
        }
        if (null === $endpoint = $composer->get_package()->get_extra()['symfony']['endpoint'] ?? null) {
            $this->endpoints = self::DEFAULT_ENDPOINTS;
        } elseif (\is_array($endpoint) || str_contains((string) $endpoint, '.json') || 'flex://defaults' === $endpoint) {
            $this->endpoints = array_values((array) $endpoint);
            if (\is_string($endpoint) && str_contains($endpoint, '.json')) {
                $this->endpoints[] = 'flex://defaults';
            }
        } else {
            $this->legacy_endpoint = rtrim((string) $endpoint, '/');
        }
        if (false === $endpoint = getenv('SYMFONY_ENDPOINT')) {
            // no-op
        } elseif (str_contains($endpoint, '.json') || 'flex://defaults' === $endpoint) {
            $this->endpoints ?? $this->endpoints = self::DEFAULT_ENDPOINTS;
            array_unshift($this->endpoints, $endpoint);
            $this->legacy_endpoint = null;
        } else {
            $this->endpoints = null;
            $this->legacy_endpoint = rtrim($endpoint, '/');
        }
        if (null !== $this->endpoints) {
            if (false !== $i = array_search('flex://defaults', $this->endpoints, true)) {
                array_splice($this->endpoints, $i, 1, self::DEFAULT_ENDPOINTS);
            }
            $this->endpoints = array_fill_keys($this->endpoints, []);
        }
        $config = $composer->get_config();
        $this->cache = new Cache($this->io, $config->get('cache-repo-dir') . '/flex');
        $this->sess = bin2hex(random_bytes(16));
        $this->composer = $composer;
    }
    public function get_session_id(): string
    {
        return $this->sess;
    }
    public function is_enabled()
    {
        return $this->enabled;
    }
    public function disable(): void
    {
        $this->enabled = false;
    }
    public function get_versions()
    {
        $this->initialize();
        return self::$versions ?? self::$versions = current($this->get([$this->legacy_endpoint . '/versions.json']));
    }
    public function get_aliases()
    {
        $this->initialize();
        return self::$aliases ?? self::$aliases = current($this->get([$this->legacy_endpoint . '/aliases.json']));
    }
    /**
     * Downloads recipes.
     *
     * @param OperationInterface[] $operations
     */
    public function get_recipes(array $operations): array
    {
        $this->initialize();
        if ($this->conflicts) {
            $locked_repository = $this->composer->get_locker()->get_locked_repository(true);
            foreach ($this->conflicts as $conflicts) {
                foreach ($conflicts as $package => $versions) {
                    foreach ($versions as $version => $conflicts) {
                        foreach ($conflicts as $conflicting_package => $constraint) {
                            if ($locked_repository->find_package($conflicting_package, $constraint)) {
                                unset($this->index[$package][$version]);
                            }
                        }
                    }
                }
            }
            $this->conflicts = [];
        }
        $data = [];
        $urls = [];
        $chunk = '';
        $recipe_ref = null;
        foreach ($operations as $operation) {
            $o = 'i';
            if ($operation instanceof Update_Operation) {
                $package = $operation->get_target_package();
                $o = 'u';
            } else {
                $package = $operation->get_package();
                if ($operation instanceof Uninstall_Operation) {
                    $o = 'r';
                }
                if ($operation instanceof Information_Operation) {
                    $recipe_ref = $operation->get_recipe_ref();
                }
            }
            $version = $package->get_pretty_version();
            if ($operation instanceof Information_Operation && $operation->get_version()) {
                $version = $operation->get_version();
            }
            if (str_starts_with((string) $version, 'dev-') && isset($package->get_extra()['branch-alias'])) {
                $branch_aliases = $package->get_extra()['branch-alias'];
                if (isset($branch_aliases[$version]) && ($alias = $branch_aliases[$version]) || isset($branch_aliases['dev-main']) && ($alias = $branch_aliases['dev-main']) || isset($branch_aliases['dev-trunk']) && ($alias = $branch_aliases['dev-trunk']) || isset($branch_aliases['dev-develop']) && ($alias = $branch_aliases['dev-develop']) || isset($branch_aliases['dev-default']) && ($alias = $branch_aliases['dev-default']) || isset($branch_aliases['dev-latest']) && ($alias = $branch_aliases['dev-latest']) || isset($branch_aliases['dev-next']) && ($alias = $branch_aliases['dev-next']) || isset($branch_aliases['dev-current']) && ($alias = $branch_aliases['dev-current']) || isset($branch_aliases['dev-support']) && ($alias = $branch_aliases['dev-support']) || isset($branch_aliases['dev-tip']) && ($alias = $branch_aliases['dev-tip']) || isset($branch_aliases['dev-master']) && $alias = $branch_aliases['dev-master']) {
                    $version = $alias;
                }
            }
            if ($recipe_versions = $this->index[$package->get_name()] ?? null) {
                $version = explode('.', (string) preg_replace('/^dev-|^v|\.x-dev$|-dev$/', '', (string) $version));
                $version = $version[0] . '.' . ($version[1] ?? '9999999');
                foreach (array_reverse($recipe_versions) as $v => $endpoint) {
                    if (version_compare($version, $v, '<')) {
                        continue;
                    }
                    $data['locks'][$package->get_name()]['version'] = $version;
                    $data['locks'][$package->get_name()]['recipe']['version'] = $v;
                    $links = $this->endpoints[$endpoint]['_links'];
                    if (null !== $recipe_ref && isset($links['archived_recipes_template'])) {
                        if (isset($links['archived_recipes_template_relative'])) {
                            $links['archived_recipes_template'] = preg_replace('{[^/\?]*+(?=\?|$)}', $links['archived_recipes_template_relative'], (string) $endpoint, 1);
                        }
                        $urls[] = strtr($links['archived_recipes_template'], ['{package_dotted}' => str_replace('/', '.', $package->get_name()), '{ref}' => $recipe_ref]);
                        break;
                    }
                    if (isset($links['recipe_template_relative'])) {
                        $links['recipe_template'] = preg_replace('{[^/\?]*+(?=\?|$)}', $links['recipe_template_relative'], (string) $endpoint, 1);
                    }
                    $urls[] = strtr($links['recipe_template'], ['{package_dotted}' => str_replace('/', '.', $package->get_name()), '{package}' => $package->get_name(), '{version}' => $v]);
                    break;
                }
                continue;
            }
            if (\is_array($recipe_versions)) {
                $data['conflicts'][$package->get_name()] = true;
            }
            if (null !== $this->endpoints) {
                continue;
            }
            // FIXME: Multi name with getNames()
            $name = str_replace('/', ',', $package->get_name());
            $path = \sprintf('%s,%s%s', $name, $o, $version);
            if ($date = $package->get_release_date()) {
                $path .= ',' . $date->format('U');
            }
            if (\strlen($chunk) + \strlen($path) > self::MAX_LENGTH) {
                $urls[] = $this->legacy_endpoint . '/p/' . $chunk;
                $chunk = $path;
            } elseif ($chunk) {
                $chunk .= ';' . $path;
            } else {
                $chunk = $path;
            }
        }
        if ($chunk) {
            $urls[] = $this->legacy_endpoint . '/p/' . $chunk;
        }
        if (null === $this->endpoints) {
            foreach ($this->get($urls, true) as $body) {
                foreach ($body['manifests'] ?? [] as $name => $manifest) {
                    $data['manifests'][$name] = $manifest;
                }
                foreach ($body['locks'] ?? [] as $name => $lock) {
                    $data['locks'][$name] = $lock;
                }
            }
        } else {
            foreach ($this->get($urls, true) as $body) {
                foreach ($body['manifests'] ?? [] as $name => $manifest) {
                    if (null === $version = $data['locks'][$name]['recipe']['version'] ?? null) {
                        continue;
                    }
                    $endpoint = $this->endpoints[$this->index[$name][$version]];
                    $data['locks'][$name]['recipe'] = ['repo' => $endpoint['_links']['repository'], 'branch' => $endpoint['branch'], 'version' => $version, 'ref' => $manifest['ref']];
                    foreach ($manifest['files'] ?? [] as $i => $file) {
                        $manifest['files'][$i]['contents'] = \is_array($file['contents']) ? implode("\n", $file['contents']) : base64_decode((string) $file['contents']);
                    }
                    $data['manifests'][$name] = $manifest + ['repository' => $endpoint['_links']['repository'], 'package' => $name, 'version' => $version, 'origin' => strtr($endpoint['_links']['origin_template'], ['{package}' => $name, '{version}' => $version]), 'is_contrib' => $endpoint['is_contrib'] ?? false];
                }
            }
        }
        return $data;
    }
    /**
     * Used to "hide" a recipe version so that the next most-recent will be returned.
     *
     * This is used when resolving "conflicts".
     */
    public function remove_recipe_from_index(string $package_name, string $version): void
    {
        unset($this->index[$package_name][$version]);
    }
    public function get_symfony_packs(array $packages): array
    {
        $packs = [];
        foreach ($this->composer->get_repository_manager()->get_repositories() as $repo) {
            if (!$packages) {
                break;
            }
            $result = $repo->load_packages($packages, Base_Package::$stabilities, []);
            foreach ($result['packages'] ?? [] as $package) {
                if (!isset($packages[$package->get_name()])) {
                    continue;
                }
                if ('symfony-pack' === $package->get_type()) {
                    $packs[$package->get_name()] = true;
                }
                unset($packages[$package->get_name()]);
            }
        }
        return array_keys($packs);
    }
    /**
     * Fetches and decodes JSON HTTP response bodies.
     */
    private function get(array $urls, bool $is_recipe = false, int $try = 3): array
    {
        $responses = [];
        $retries = [];
        $options = [];
        foreach ($urls as $url) {
            $cache_key = self::generate_cache_key($url);
            $headers = [];
            if (preg_match('{^https?://api\.github\.com/}', (string) $url)) {
                $headers[] = 'Accept: application/vnd.github.v3.raw';
            } elseif (preg_match('{^https?://raw\.githubusercontent\.com/}', (string) $url) && $this->io->has_authentication('github.com')) {
                $auth = $this->io->get_authentication('github.com');
                if ('x-oauth-basic' === $auth['password']) {
                    $headers[] = 'Authorization: token ' . $auth['username'];
                }
            } elseif ($this->legacy_endpoint) {
                $headers[] = 'Package-Session: ' . $this->sess;
            }
            if ($contents = $this->cache->read($cache_key)) {
                $cached_response = Response::from_json(json_decode($contents, true));
                if ($last_modified = $cached_response->get_header('last-modified')) {
                    $headers[] = 'If-Modified-Since: ' . $last_modified;
                }
                if ($e_tag = $cached_response->get_header('etag')) {
                    $headers[] = 'If-None-Match: ' . $e_tag;
                }
                $responses[$url] = $cached_response->get_body();
            }
            $options[$url] = $this->get_options($headers);
        }
        $loop = new Loop($this->rfs);
        $jobs = [];
        foreach ($urls as $url) {
            $jobs[] = $this->rfs->add($url, $options[$url])->then(function (Composer_Response $response) use ($url, &$responses): void {
                if (200 === $response->get_status_code()) {
                    $cache_key = self::generate_cache_key($url);
                    $responses[$url] = $this->parse_json($response->get_body(), $url, $cache_key, $response->get_headers())->get_body();
                }
            }, function (\Exception $e) use ($url, &$retries): void {
                $retries[] = [$url, $e];
            });
        }
        $loop->wait($jobs);
        if (!$retries) {
            return $responses;
        }
        if (0 < --$try) {
            usleep(100000);
            return $this->get(array_column($retries, 0), $is_recipe, $try) + $responses;
        }
        foreach ($retries as [$url, $e]) {
            if (isset($responses[$url])) {
                $this->switch_to_degraded_mode($e, $url);
            } elseif ($is_recipe) {
                $this->io->write_error('<warning>Failed to download recipe: ' . $e->get_message() . '</>');
            } else {
                throw $e;
            }
        }
        return $responses;
    }
    private function parse_json(string $json, string $url, string $cache_key, array $last_headers): Response
    {
        $data = Json_File::parse_json($json, $url);
        if (!empty($data['warning'])) {
            $this->io->write_error('<warning>Warning from ' . $url . ': ' . $data['warning'] . '</>');
        }
        if (!empty($data['info'])) {
            $this->io->write_error('<info>Info from ' . $url . ': ' . $data['info'] . '</>');
        }
        $response = new Response($data, $last_headers);
        if ($cache_key && ($response->get_header('last-modified') || $response->get_header('etag'))) {
            $this->cache->write($cache_key, json_encode($response));
        }
        return $response;
    }
    private function switch_to_degraded_mode(\Exception $e, string $url): void
    {
        if (!$this->degraded_mode) {
            $this->io->write_error('<warning>' . $e->get_message() . '</>');
            $this->io->write_error('<warning>' . $url . ' could not be fully loaded, package information was loaded from the local cache and may be out of date</>');
        }
        $this->degraded_mode = true;
    }
    private function get_options(array $headers): array
    {
        $options = ['http' => ['header' => $headers]];
        if (null !== $this->ca_file) {
            $options['ssl']['cafile'] = $this->ca_file;
        }
        return $options;
    }
    private function initialize(): void
    {
        if (null !== $this->index || null === $this->endpoints) {
            $this->index ?? $this->index = [];
            return;
        }
        $indexes = self::$versions = self::$aliases = [];
        foreach ($this->get(array_keys($this->endpoints)) as $endpoint => $index) {
            $indexes[$endpoint] = $index;
        }
        foreach ($this->endpoints as $endpoint => $config) {
            $config = $indexes[$endpoint] ?? [];
            foreach ($config['recipes'] ?? [] as $package => $versions) {
                $this->index[$package] ??= array_fill_keys($versions, $endpoint);
            }
            $this->conflicts[] = $config['recipe-conflicts'] ?? [];
            self::$versions += $config['versions'] ?? [];
            self::$aliases += $config['aliases'] ?? [];
            unset($config['recipes'], $config['recipe-conflicts'], $config['versions'], $config['aliases']);
            $this->endpoints[$endpoint] = $config;
        }
    }
    private static function generate_cache_key(string $url): string
    {
        $url = preg_replace('{^https://api.github.com/repos/([^/]++/[^/]++)/contents/}', '$1/', $url);
        $url = preg_replace('{^https://raw.githubusercontent.com/([^/]++/[^/]++)/}', '$1/', (string) $url);
        $key = preg_replace('{[^a-z0-9.]}i', '-', (string) $url);
        // eCryptfs can have problems with filenames longer than around 143 chars
        return \strlen((string) $key) > 140 ? md5((string) $url) : $key;
    }
}