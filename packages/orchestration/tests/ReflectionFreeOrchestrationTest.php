<?php

declare(strict_types=1);

/** @return list<string> basenames under $dir whose contents reference boot-time reflection */
function orchestrationReflectionHits(string $dir): array
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

/**
 * Production loads the compiled OrchestrationManifest by require; reflection is allowed in exactly one file,
 * the scanner that compiles it (Task 16 adds it and widens this list to that one name).
 */
it('confines reflection in firefly/orchestration to the scanner', function (): void {
    expect(orchestrationReflectionHits(__DIR__.'/../src'))->toBe([]);
});
