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

use Composer\Factory;
use Composer\Json\Json_File;
use Composer\Json\Json_Manipulator;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\Recipe_Update;
/**
 * @author Marcin Morawski <marcin@morawskim.pl>
 */
class Composer_Commands_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $scripts, Lock $lock, array $options = []): void
    {
        $json = new Json_File(Factory::get_composer_file());
        file_put_contents($json->get_path(), $this->configure_scripts($scripts, $json));
    }
    public function unconfigure(Recipe $recipe, $scripts, Lock $lock): void
    {
        $json = new Json_File(Factory::get_composer_file());
        $manipulator = new Json_Manipulator(file_get_contents($json->get_path()));
        foreach ($scripts as $key => $command) {
            $manipulator->remove_sub_node('scripts', $key);
        }
        file_put_contents($json->get_path(), $manipulator->get_contents());
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        $json = new Json_File(Factory::get_composer_file());
        $json_path = $json->get_path();
        if (str_starts_with($json_path, $recipe_update->get_root_dir())) {
            $json_path = substr($json_path, \strlen($recipe_update->get_root_dir()));
        }
        $json_path = ltrim($json_path, '/\\');
        $recipe_update->set_original_file($json_path, $this->configure_scripts($original_config, $json));
        $recipe_update->set_new_file($json_path, $this->configure_scripts($new_config, $json));
    }
    private function configure_scripts(array $scripts, Json_File $json): string
    {
        $manipulator = new Json_Manipulator(file_get_contents($json->get_path()));
        foreach ($scripts as $cmd_name => $script) {
            $manipulator->add_sub_node('scripts', $cmd_name, $script);
        }
        return $manipulator->get_contents();
    }
}