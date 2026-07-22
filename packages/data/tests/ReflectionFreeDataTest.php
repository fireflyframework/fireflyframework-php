<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files under $dir that name boot/scan-time attribute introspection */
function dataReflectionHits(string $dir): array
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

it('confines firefly/data reflection to the sanctioned scanner + factory', function () {
    // Nothing reflects yet. Task 5 adds TransactionalScanner.php; Task 7 adds ProxyFactory.php.
    // Final expected value: ['ProxyFactory.php', 'TransactionalScanner.php'] (sorted).
    expect(dataReflectionHits(__DIR__.'/../src'))->toBe([]);
});
