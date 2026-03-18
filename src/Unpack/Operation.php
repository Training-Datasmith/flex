<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Flex\Unpack;

class Operation
{
    private array $packages = [];

    public function __construct(private readonly bool $unpack, private readonly bool $sort)
    {
    }

    public function addPackage(string $name, string $version, bool $dev): void
    {
        $this->packages[] = [
            'name' => $name,
            'version' => $version,
            'dev' => $dev,
        ];
    }

    public function getPackages(): array
    {
        return $this->packages;
    }

    public function shouldUnpack(): bool
    {
        return $this->unpack;
    }

    public function shouldSort(): bool
    {
        return $this->sort;
    }
}
