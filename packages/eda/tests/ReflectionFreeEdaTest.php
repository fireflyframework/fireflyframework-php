<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files under $dir that name boot/scan-time attribute introspection */
function edaReflectionHits(string $dir): array
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

it('confines firefly/eda reflection to the single sanctioned EventListenerScanner', function () {
    // EventListenerScanner is the SOLE sanctioned reflection site in packages/eda/src. Docblocks count —
    // no other eda/src file (comments included) may name ReflectionClass/ReflectionMethod/getAttributes.
    expect(edaReflectionHits(__DIR__.'/../src'))->toBe(['EventListenerScanner.php']);
});
