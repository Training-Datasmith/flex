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
namespace Symfony\Flex\Unpack;

use Composer\Package\Package_Interface;
class Result
{
    private array $unpacked = [];
    private array $required = [];
    public function add_unpacked(Package_Interface $package): bool
    {
        $name = $package->get_name();
        if (!isset($this->unpacked[$name])) {
            $this->unpacked[$name] = $package;
            return true;
        }
        return false;
    }
    /**
     * @return PackageInterface[]
     */
    public function get_unpacked(): array
    {
        return $this->unpacked;
    }
    public function add_required(string $package): void
    {
        $this->required[] = $package;
    }
    /**
     * @return string[]
     */
    public function get_required(): array
    {
        // we need at least one package for the command to work properly
        return $this->required ?: ['symfony/flex'];
    }
}