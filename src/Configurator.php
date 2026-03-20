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
use Composer\IO\Io_Interface;
use Symfony\Flex\Configurator\Abstract_Configurator;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Configurator
{
    private array $configurators;
    private array $post_install_configurators;
    private ?array $cache = null;
    public function __construct(private readonly Composer $composer, private readonly Io_Interface $io, private readonly Options $options)
    {
        // ordered list of configurators
        $this->configurators = ['bundles' => Configurator\Bundles_Configurator::class, 'copy-from-recipe' => Configurator\Copy_From_Recipe_Configurator::class, 'copy-from-package' => Configurator\Copy_From_Package_Configurator::class, 'env' => Configurator\Env_Configurator::class, 'dotenv' => Configurator\Dotenv_Configurator::class, 'container' => Configurator\Container_Configurator::class, 'makefile' => Configurator\Makefile_Configurator::class, 'composer-scripts' => Configurator\Composer_Scripts_Configurator::class, 'composer-commands' => Configurator\Composer_Commands_Configurator::class, 'gitignore' => Configurator\Gitignore_Configurator::class, 'dockerfile' => Configurator\Dockerfile_Configurator::class, 'docker-compose' => Configurator\Docker_Compose_Configurator::class];
        $this->post_install_configurators = ['add-lines' => Configurator\Add_Lines_Configurator::class];
    }
    public function install(Recipe $recipe, Lock $lock, array $options = []): void
    {
        $manifest = $recipe->get_manifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key], $lock, $options);
            }
        }
    }
    /**
     * Run after all recipes have been installed to run post-install configurators.
     */
    public function post_install(Recipe $recipe, Lock $lock, array $options = []): void
    {
        $manifest = $recipe->get_manifest();
        foreach (array_keys($this->post_install_configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key], $lock, $options);
            }
        }
    }
    public function populate_update(Recipe_Update $recipe_update): void
    {
        $original_manifest = $recipe_update->get_original_recipe()->get_manifest();
        $new_manifest = $recipe_update->get_new_recipe()->get_manifest();
        $all_configurators = array_merge($this->configurators, $this->post_install_configurators);
        foreach (array_keys($all_configurators) as $key) {
            if (!isset($original_manifest[$key]) && !isset($new_manifest[$key])) {
                continue;
            }
            $this->get($key)->update($recipe_update, $original_manifest[$key] ?? [], $new_manifest[$key] ?? []);
        }
    }
    public function unconfigure(Recipe $recipe, Lock $lock): void
    {
        $manifest = $recipe->get_manifest();
        $all_configurators = array_merge($this->configurators, $this->post_install_configurators);
        foreach (array_keys($all_configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->unconfigure($recipe, $manifest[$key], $lock);
            }
        }
    }
    private function get(int|string $key): Abstract_Configurator
    {
        if (!isset($this->configurators[$key]) && !isset($this->post_install_configurators[$key])) {
            throw new \InvalidArgumentException(\sprintf('Unknown configurator "%s".', $key));
        }
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $class = $this->configurators[$key] ?? $this->post_install_configurators[$key];
        return $this->cache[$key] = new $class($this->composer, $this->io, $this->options);
    }
}