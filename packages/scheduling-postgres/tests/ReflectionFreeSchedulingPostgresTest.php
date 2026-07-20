<?php

declare(strict_types=1);

/** @return list<string> basenames under $dir whose contents reference boot-time reflection */
function schedulingPostgresReflectionHits(string $dir): array
{
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = (string) file_get_contents((string) $file->getRealPath());
        if (preg_match('/ReflectionClass|ReflectionMethod|getAttributes/', $contents) === 1) {
            $hits[] = $file->getBasename();
        }
    }
    sort($hits);

    return $hits;
}

it('the firefly/scheduling-postgres adapter uses no boot-time reflection', function () {
    expect(schedulingPostgresReflectionHits(__DIR__.'/../src'))->toBe([]);
});
