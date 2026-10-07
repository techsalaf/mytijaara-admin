<?php

namespace App\Services;

/** Conservative local-filesystem boundary, shared by registration transports. */
final class PrivateRegistrationStorageGuard
{
    public static function assertPrivate(string $directory): void
    {
        $candidate = self::resolve($directory).'/';
        // The parent is intentionally excluded too: XAMPP serves htdocs, not just this checkout.
        $parent = dirname(base_path());
        $roots = [base_path(), dirname($parent) !== $parent ? $parent : null, $_SERVER['DOCUMENT_ROOT'] ?? null, config('filesystems.registration_public_root')];
        foreach (array_filter($roots) as $root) {
            if (str_starts_with($candidate, self::resolve($root).'/')) {
                throw new \RuntimeException('Private registration data must be outside the served tree.');
            }
        }
    }

    private static function resolve(string $path): string
    {
        $tail = [];
        $ancestor = rtrim($path, '/\\');
        if ($ancestor === '' && str_starts_with($path, '/')) {
            $ancestor = '/';
        }
        if (preg_match('/^[a-z]:$/iD', $ancestor)) {
            $ancestor .= '/';
        }
        while (($resolved = realpath($ancestor)) === false) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor || $ancestor === '') {
                throw new \RuntimeException('Invalid private filesystem root.');
            }
            $tail[] = basename($ancestor);
            $ancestor = $parent;
        }
        $joined = str_replace('\\', '/', $resolved).'/'.implode('/', array_reverse($tail));
        $parts = [];
        foreach (explode('/', $joined) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $part;
        }

        return strtolower((str_starts_with($joined, '/') ? '/' : '').implode('/', $parts));
    }
}
