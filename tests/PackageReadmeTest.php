<?php

declare(strict_types=1);

it('ships a house-style README in every package', function () {
    foreach (glob(dirname(__DIR__).'/packages/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $readme = $dir.'/README.md';
        expect(is_file($readme))->toBeTrue('missing README in '.$dir);
        $name = basename($dir);
        $contents = (string) file_get_contents($readme);
        expect($contents)->toContain("# firefly/{$name}")
            ->and($contents)->toContain('Apache-2.0 © Firefly Software Solutions Inc.');
    }
});

/**
 * A package README is the front door, and a roadmap nailed to it rots silently.
 *
 * "X lands later" is free to write, reads as helpful, and becomes a lie the moment X ships — which is the
 * one event that guarantees nobody reopens the README, because the author is busy writing the module
 * document and the changelog entry instead. Three of them were live when this was written, each one
 * contradicted by code in its own package: `firefly/resilience` promised `#[Retry]` "in SP-5 with AOP"
 * with `src/Method/Retry.php` beside it, `firefly/observability` offered a `NoOpTracer` "today" with
 * `src/Tracing/OpenTelemetry/OpenTelemetryTracer.php` beside it, and `firefly/validation` deferred
 * `#[Valid]` interception to "M6/web" some twenty milestones after firefly/web shipped it. All three had
 * a passing test suite, a full module document and a changelog entry announcing the very feature the
 * README denied: a reader who starts at the front door is told the headline feature does not exist.
 *
 * So the rule is the blunt one, and deliberately so: a package README describes what the package DOES.
 * The roadmap belongs where it is maintained — the plan, the `[Unreleased]` changelog, the issue — all of
 * which are read when the feature lands. This cannot check whether prose is TRUE (ModuleDocumentationTest
 * says the same about a module document's quality); it can hold the one shape that is reliably false
 * later, and it costs a sentence rewrite to satisfy honestly.
 */
it('never nails a roadmap to a package README', function () {
    $promises = [
        '/\blands?\s+(in|later|with)\b/i',
        '/\b(will|to)\s+(land|ship|arrive)\b/i',
        '/\b(ships?|arrives?|comes?)\s+later\b/i',
        '/\bfuture work\b/i',
        '/\bcoming soon\b/i',
        '/\bplanned for\b/i',
        '/\bnot yet (implemented|shipped|available)\b/i',
        '/\bTODO\b/',
    ];

    $found = [];

    foreach (glob(dirname(__DIR__).'/packages/*/README.md') ?: [] as $readme) {
        $lines = explode("\n", (string) file_get_contents($readme));

        foreach ($lines as $number => $line) {
            foreach ($promises as $promise) {
                if (preg_match($promise, $line, $matches) === 1) {
                    $found[] = basename(dirname($readme)).'/README.md:'.($number + 1).' — "'.trim($matches[0]).'"';
                }
            }
        }
    }

    expect($found)->toBe([]);
});
