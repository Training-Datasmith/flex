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
class Dotenv_Configurator extends Abstract_Configurator
{
    public function configure(Recipe $recipe, $vars, Lock $lock, array $options = []): void
    {
        foreach ($vars as $suffix => $vars) {
            $configurator = new Env_Configurator($this->composer, $this->io, $this->options, $suffix);
            $configurator->configure($recipe, $vars, $lock, $options);
        }
    }
    public function unconfigure(Recipe $recipe, $vars, Lock $lock): void
    {
        foreach ($vars as $suffix => $vars) {
            $configurator = new Env_Configurator($this->composer, $this->io, $this->options, $suffix);
            $configurator->unconfigure($recipe, $vars, $lock);
        }
    }
    public function update(Recipe_Update $recipe_update, array $original_config, array $new_config): void
    {
        foreach ($original_config as $suffix => $vars) {
            $configurator = new Env_Configurator($this->composer, $this->io, $this->options, $suffix);
            $configurator->update($recipe_update, $vars, $new_config[$suffix] ?? []);
        }
        foreach ($new_config as $suffix => $vars) {
            if (!isset($original_config[$suffix])) {
                $configurator = new Env_Configurator($this->composer, $this->io, $this->options, $suffix);
                $configurator->update($recipe_update, [], $vars);
            }
        }
    }
}