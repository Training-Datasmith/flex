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

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
class Recipe_Update
{
    /** @var string[] */
    private array $original_recipe_files = [];
    /** @var string[] */
    private array $new_recipe_files = [];
    private array $copy_from_package_paths = [];
    public function __construct(private readonly Recipe $original_recipe, private readonly Recipe $new_recipe, private readonly Lock $lock, private readonly string $root_dir)
    {
    }
    public function get_original_recipe(): Recipe
    {
        return $this->original_recipe;
    }
    public function get_new_recipe(): Recipe
    {
        return $this->new_recipe;
    }
    public function get_lock(): Lock
    {
        return $this->lock;
    }
    public function get_root_dir(): string
    {
        return $this->root_dir;
    }
    public function get_package_name(): string
    {
        return $this->original_recipe->get_name();
    }
    public function set_original_file(string $filename, ?string $contents): void
    {
        $this->original_recipe_files[$filename] = $contents;
    }
    public function set_new_file(string $filename, ?string $contents): void
    {
        $this->new_recipe_files[$filename] = $contents;
    }
    public function add_original_files(array $files): void
    {
        foreach ($files as $file => $contents) {
            if (null === $contents) {
                continue;
            }
            $this->set_original_file($file, $contents);
        }
    }
    public function add_new_files(array $files): void
    {
        foreach ($files as $file => $contents) {
            if (null === $contents) {
                continue;
            }
            $this->set_new_file($file, $contents);
        }
    }
    public function get_original_files(): array
    {
        return $this->original_recipe_files;
    }
    public function get_new_files(): array
    {
        return $this->new_recipe_files;
    }
    public function get_copy_from_package_paths(): array
    {
        return $this->copy_from_package_paths;
    }
    public function add_copy_from_package_path(string $source, string $target): void
    {
        $this->copy_from_package_paths[$source] = $target;
    }
}