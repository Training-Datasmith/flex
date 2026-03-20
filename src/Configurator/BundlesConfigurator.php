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
namespace Symfony\Flex\Configurator;

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Bundles_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $bundles, Lock $lock, array $options = []): void
    {
        $this->write('Enabling the package as a Symfony bundle');
        $registered = $this->configure_bundles($bundles);
        $this->dump($this->get_conf_file(), $registered);
    }
    public function unconfigure(Recipe $recipe, $bundles, Lock $lock): void
    {
        $this->write('Disabling the Symfony bundle');
        $file = $this->get_conf_file();
        if (!file_exists($file)) {
            return;
        }
        $registered = $this->load($file);
        foreach (array_keys($this->prepare_bundles($bundles)) as $class) {
            unset($registered[$class]);
        }
        $this->dump($file, $registered);
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $original_bundles = $this->configure_bundles($original_config, true);
        $recipe_update->set_original_file($this->get_local_conf_file(), $this->build_contents($original_bundles));
        $new_bundles = $this->configure_bundles($new_config, true);
        $recipe_update->set_new_file($this->get_local_conf_file(), $this->build_contents($new_bundles));
    }
    private function configure_bundles(array $bundles, bool $reset_environments = false): array
    {
        $file = $this->get_conf_file();
        $registered = $this->load($file);
        $classes = $this->prepare_bundles($bundles);
        if (isset($classes[$fwb = 'Symfony\Bundle\FrameworkBundle\FrameworkBundle'])) {
            foreach ($classes[$fwb] as $env) {
                $registered[$fwb][$env] = true;
            }
            unset($classes[$fwb]);
        }
        foreach ($classes as $class => $envs) {
            // do not override existing configured envs for a bundle
            if (!isset($registered[$class]) || $reset_environments) {
                if ($reset_environments) {
                    // used during calculating an "upgrade"
                    // here, we want to "undo" the bundle's configuration entirely
                    // then re-add it fresh, in case some environments have been
                    // removed in an updated version of the recipe
                    $registered[$class] = [];
                }
                foreach ($envs as $env) {
                    $registered[$class][$env] = true;
                }
            }
        }
        return $registered;
    }
    private function prepare_bundles(array $bundles): array
    {
        foreach ($bundles as $class => $envs) {
            $bundles[ltrim((string) $class, '\\')] = $envs;
        }
        return $bundles;
    }
    private function load(string $file): array
    {
        $bundles = file_exists($file) ? require $file : [];
        if (!\is_array($bundles)) {
            return [];
        }
        return $bundles;
    }
    private function dump(string $file, array $bundles): void
    {
        $contents = $this->build_contents($bundles);
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($file);
        }
    }
    private function build_contents(array $bundles): string
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($bundles as $class => $envs) {
            $contents .= "    {$class}::class => [";
            foreach ($envs as $env => $value) {
                $boolean_value = var_export($value, true);
                $contents .= "'{$env}' => {$boolean_value}, ";
            }
            $contents = substr($contents, 0, -2) . "],\n";
        }
        return $contents . "];\n";
    }
    private function get_conf_file(): string
    {
        return $this->options->get('root-dir') . '/' . $this->get_local_conf_file();
    }
    private function get_local_conf_file(): string
    {
        return $this->options->expand_target_dir('%CONFIG_DIR%/bundles.php');
    }
}