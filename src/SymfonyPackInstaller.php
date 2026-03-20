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

use Composer\Installer\Metapackage_Installer;
class Symfony_Pack_Installer extends Metapackage_Installer
{
    public function supports($package_type): bool
    {
        return 'symfony-pack' === $package_type;
    }
}