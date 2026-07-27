<?php

declare(strict_types=1);

namespace Firefly\Cli\Cache;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Enumerates every declared class under a PSR-4 map — the constraint class-list source (validation compiles from a class list, not a PSR-4 scan). Mirrors the scanners' recursive-walk + class_exists idiom. */
final class ClassEnumerator
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<class-string>
     */
    public function enumerate(array $psr4): array
    {
        $classes = [];
        foreach ($psr4 as $prefix => $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            /** @var iterable<\SplFileInfo> $it */
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1, -4);
                $class = rtrim($prefix, '\\').'\\'.str_replace('/', '\\', $relative);
                if (class_exists($class)) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
