<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\Definition\FlagDocument;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Paths into the byte-identical conformance folder both frameworks carry (spec §4.10). */
final class ConformanceFiles
{
    public static function root(): string
    {
        return dirname(__DIR__).'/Conformance';
    }

    /**
     * Every file under the folder except MANIFEST.sha256, relative, in byte order (LC_ALL=C, as the manifest).
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $root = self::root();
        $files = [];
        /** @var iterable<SplFileInfo> $walk */
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isFile() && $relative !== 'MANIFEST.sha256') {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    public static function gherkinDirectory(): string
    {
        return self::root().'/testbed/evaluator/gherkin';
    }

    public static function testkitDocument(): FlagDocument
    {
        return FlagDocument::fromJson((string) file_get_contents(self::root().'/testbed/evaluator/flags/testkit-flags.json'));
    }
}
