<?php

declare (strict_types=1);
namespace Symfony\Flex;

use Composer\Dependency_Resolver\Operation\Operation_Interface;
use Composer\Package\Package_Interface;
/**
 * @author Maxime Hélias <maximehelias16@gmail.com>
 */
class Information_Operation implements Operation_Interface, \Stringable
{
    private $package;
    private ?string $recipe_ref = null;
    private ?string $version = null;
    public function __construct(Package_Interface $package)
    {
        $this->package = $package;
    }
    /**
     * Call to get information about a specific version of a recipe.
     *
     * Both $recipeRef and $version would normally come from the symfony.lock file.
     */
    public function set_specific_recipe_version(string $recipe_ref, string $version): void
    {
        $this->recipe_ref = $recipe_ref;
        $this->version = $version;
    }
    /**
     * Returns package instance.
     *
     * @return PackageInterface
     */
    public function get_package()
    {
        return $this->package;
    }
    public function get_recipe_ref(): ?string
    {
        return $this->recipe_ref;
    }
    public function get_version(): ?string
    {
        return $this->version;
    }
    public function get_job_type(): string
    {
        return 'information';
    }
    public function get_operation_type(): string
    {
        return 'information';
    }
    public function show($lock): string
    {
        $pretty = method_exists($this->package, 'getFullPrettyVersion') ? $this->package->get_full_pretty_version() : $this->format_version($this->package);
        return 'Information ' . $this->package->get_pretty_name() . ' (' . $pretty . ')';
    }
    public function __toString(): string
    {
        return $this->show(false);
    }
    /**
     * Compatibility for Composer 1.x, not needed in Composer 2.
     */
    public function get_reason()
    {
        return null;
    }
}