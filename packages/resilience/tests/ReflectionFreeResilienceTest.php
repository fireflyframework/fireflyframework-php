<?php

declare(strict_types=1);

/** @return list<string> basenames of .php files under $dir that name boot/scan-time attribute introspection */
function resilienceReflectionHits(string $dir): array
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

it('confines firefly/resilience reflection to the single sanctioned ResilienceMethodScanner', function () {
    // ResilienceMethodScanner is the SOLE sanctioned reflection site in packages/resilience/src — the
    // scan-time half of #[Retry]/#[CircuitBreaker]/#[RateLimiter]/#[Bulkhead]/#[TimeLimiter]/#[Fallback],
    // which runs at `firefly:cache` time and never on a cached boot. The assertion is still an EXACT set,
    // not a floor: docblocks count, so no other resilience/src file (comments included) may name
    // ReflectionClass/ReflectionMethod/getAttributes, and the programmatic patterns stay reflection-free.
    expect(resilienceReflectionHits(__DIR__.'/../src'))->toBe(['ResilienceMethodScanner.php']);
});
