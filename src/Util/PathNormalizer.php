<?php

declare(strict_types=1);

namespace PhpPacker\Util;

final class PathNormalizer
{
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $normalizedPath = preg_replace('#/+#', '/', $path);
        if (null === $normalizedPath) {
            return $path;
        }

        $parts = explode('/', $normalizedPath);
        $absolute = [];

        foreach ($parts as $part) {
            if ('.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($absolute);
            } else {
                $absolute[] = $part;
            }
        }

        return implode('/', $absolute);
    }
}
