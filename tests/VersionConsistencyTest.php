<?php

declare(strict_types=1);

use Firefly\Kernel\Version;

it('matches the latest CHANGELOG entry', function () {
    $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');
    expect($changelog)->toBeString();

    // The first "## [x.y.z]" heading is the current release.
    preg_match('/^##\s*\[(\d{2}\.\d{2}\.\d+)\]/m', (string) $changelog, $m);

    expect($m[1] ?? null)->toBe(Version::VERSION);
});

it('matches the README version badge', function () {
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toBeString();

    preg_match('/badge\/version-(\d{2}\.\d{2}\.\d+)-/', (string) $readme, $m);

    expect($m[1] ?? null)->toBe(Version::VERSION);
});
