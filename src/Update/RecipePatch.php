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
namespace Symfony\Flex\Update;

class Recipe_Patch
{
    public function __construct(private readonly string $patch, private readonly array $blobs, private readonly array $deleted_files, private readonly array $removed_patches = [])
    {
    }
    public function get_patch(): string
    {
        return $this->patch;
    }
    public function get_blobs(): array
    {
        return $this->blobs;
    }
    public function get_deleted_files(): array
    {
        return $this->deleted_files;
    }
    /**
     * Patches for modified files that were removed because the file
     * has been deleted in the user's project.
     */
    public function get_removed_patches(): array
    {
        return $this->removed_patches;
    }
}