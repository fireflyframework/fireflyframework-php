<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Tests\Support\ConformanceFiles;
use Firefly\FeatureFlags\Tests\Support\FireflyVectors;

it('carries exactly the files MANIFEST.sha256 lists, byte for byte', function (): void {
    $expected = [];
    foreach (file(ConformanceFiles::root().'/MANIFEST.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$hash, $path] = explode('  ', $line, 2);
        $expected[$path] = $hash;
    }

    $actual = [];
    foreach (ConformanceFiles::files() as $path) {
        $actual[$path] = (string) hash_file('sha256', ConformanceFiles::root().'/'.$path);
    }

    expect($expected)->not->toBeEmpty()
        ->and($actual)->toBe($expected);
});

it('reads a version-1 vector file whose every case has a known kind', function (): void {
    $document = FireflyVectors::document();
    $kinds = array_map(static function (mixed $case): string {
        $kind = Json::members($case)['kind'] ?? null;

        return is_string($kind) ? $kind : '';
    }, (array) ($document['cases'] ?? []));

    expect($document['version'] ?? null)->toBe(1)
        ->and(FireflyVectors::today())->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(array_values(array_diff($kinds, FireflyVectors::KINDS)))->toBe([]);

    foreach (FireflyVectors::KINDS as $kind) {
        expect(FireflyVectors::cases($kind))->not->toBeEmpty();
    }
});
