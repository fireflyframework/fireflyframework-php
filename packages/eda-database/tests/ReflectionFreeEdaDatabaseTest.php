<?php

declare(strict_types=1);

/** @return list<string> basenames under $dir whose contents reference boot-time reflection */
function edaDatabaseReflectionHits(string $dir): array
{
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match('/ReflectionClass|ReflectionMethod|getAttributes/', (string) file_get_contents((string) $file->getRealPath())) === 1) {
            $hits[] = $file->getBasename();
        }
    }
    sort($hits);

    return $hits;
}

it('the firefly/eda-database adapter uses no boot-time reflection', function () {
    expect(edaDatabaseReflectionHits(__DIR__.'/../src'))->toBe([]);
});
