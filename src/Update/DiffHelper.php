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

class Diff_Helper
{
    public static function remove_files_from_patch(string $patch, array $files, array &$removed_patches): string
    {
        foreach ($files as $filename) {
            $start = strpos($patch, \sprintf('diff --git a/%s b/%s', $filename, $filename));
            if (false === $start) {
                throw new \LogicException(\sprintf('Could not find file "%s" in the patch.', $filename));
            }
            $end = strpos($patch, 'diff --git a/', $start + 1);
            $content_before = substr($patch, 0, $start);
            if (false === $end) {
                // last patch in the file
                $removed_patches[$filename] = rtrim(substr($patch, $start), "\n");
                $patch = rtrim($content_before, "\n");
                continue;
            }
            $removed_patches[$filename] = rtrim(substr($patch, $start, $end - $start), "\n");
            $patch = $content_before . substr($patch, $end);
        }
        // valid patches end with a blank line
        if ($patch && "\n" !== substr($patch, \strlen($patch) - 1, 1)) {
            $patch .= "\n";
        }
        return $patch;
    }
}