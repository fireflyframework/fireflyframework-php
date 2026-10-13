<?php

declare(strict_types=1);

/** @return list<string> basenames under $dir whose contents reference boot-time reflection */
function eventSourcingReflectionHits(string $dir): array
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
 * Production loads the compiled EventSourcingManifest by require; reflection is allowed in exactly one file, the
 * scanner that compiles it (at firefly:cache time, or as the uncached development fallback). Until Task 8 adds that
 * scanner there is none at all.
 */
it('confines reflection in firefly/eventsourcing to the scanner', function (): void {
    expect(eventSourcingReflectionHits(__DIR__.'/../src'))->toBe([]);
});
