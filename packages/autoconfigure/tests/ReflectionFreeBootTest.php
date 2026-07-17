<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files under $dir that reference boot-time reflection */
function reflectionHits(string $dir): array
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

it('the boot path of firefly/autoconfigure and firefly/validation contains no reflection', function () {
    expect(reflectionHits(__DIR__.'/../src'))->toBe([])
        ->and(reflectionHits(dirname(__DIR__, 2).'/validation/src'))->toBe(['ConstraintScanner.php']);
});

it('firefly/context reflection is still confined to its one scanner (standing M4 invariant)', function () {
    expect(reflectionHits(dirname(__DIR__, 2).'/context/src'))->toBe(['ContextScanner.php']);
});
