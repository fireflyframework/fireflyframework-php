<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files under $dir that name boot/scan-time attribute introspection */
function domainReflectionHits(string $dir): array
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

it('keeps firefly/domain entirely free of boot-time attribute introspection', function () {
    expect(domainReflectionHits(__DIR__.'/../src'))->toBe([]);
});
