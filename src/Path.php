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

/**
 * @internal
 */
class Path
{
    public function __construct(private $working_directory)
    {
    }
    public function relativize(string $absolute_path): string
    {
        $relative_path = str_replace($this->working_directory, '.', $absolute_path);
        return is_dir($absolute_path) ? rtrim($relative_path, '/') . '/' : $relative_path;
    }
    public function concatenate(array $parts): string
    {
        $first = array_shift($parts);
        return array_reduce($parts, fn(string $initial, string $next): string => rtrim($initial, '/') . '/' . ltrim($next, '/'), $first);
    }
}