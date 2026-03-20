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

use Composer\Package\Package_Interface;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Recipe
{
    private $package;
    public function __construct(Package_Interface $package, private readonly string $name, private readonly string $job, private array $data, private array $lock = [])
    {
        $this->package = $package;
    }
    public function get_package(): Package_Interface
    {
        return $this->package;
    }
    public function get_name(): string
    {
        return $this->name;
    }
    public function get_job(): string
    {
        return $this->job;
    }
    public function get_manifest(): array
    {
        if (!isset($this->data['manifest'])) {
            throw new \LogicException(\sprintf('Manifest is not available for recipe "%s".', $this->name));
        }
        return $this->data['manifest'];
    }
    public function get_files(): array
    {
        return $this->data['files'] ?? [];
    }
    public function get_origin(): string
    {
        return $this->data['origin'] ?? '';
    }
    public function get_formatted_origin(): string
    {
        if (!$this->get_origin()) {
            return '';
        }
        // symfony/translation:3.3@github.com/symfony/recipes:branch
        if (!preg_match('/^([^:]++):([^@]++)@(.+)$/', $this->get_origin(), $matches)) {
            return $this->get_origin();
        }
        return \sprintf('<info>%s</> (<comment>>=%s</>): From %s', $matches[1], $matches[2], 'auto-generated recipe' === $matches[3] ? '<comment>' . $matches[3] . '</>' : $matches[3]);
    }
    public function get_url(): string
    {
        if (!$this->data['origin']) {
            return '';
        }
        // symfony/translation:3.3@github.com/symfony/recipes:branch
        if (!preg_match('/^([^:]++):([^@]++)@([^:]++):(.+)$/', (string) $this->data['origin'], $matches)) {
            // that excludes auto-generated recipes, which is what we want
            return '';
        }
        return \sprintf('https://%s/tree/%s/%s/%s', $matches[3], $matches[4], $matches[1], $matches[2]);
    }
    public function is_contrib(): bool
    {
        return $this->data['is_contrib'] ?? false;
    }
    public function get_ref()
    {
        return $this->lock['recipe']['ref'] ?? null;
    }
    public function is_auto(): bool
    {
        return !isset($this->lock['recipe']);
    }
    public function get_version(): string
    {
        return $this->lock['recipe']['version'] ?? $this->lock['version'];
    }
    public function get_lock(): array
    {
        return $this->lock;
    }
}