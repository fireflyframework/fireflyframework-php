<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Registry\SourceState;

it('round-trips through the cache array and reports UP, STALE and DOWN', function (): void {
    $initial = SourceState::initial('file');
    $loaded = $initial->withLoaded(FlagDocument::fromJson('{"flags":{"a":{"state":"ENABLED","variants":{"e":{}},"defaultVariant":"e"}}}'), '10-20', 1790000000.0);
    $stale = $loaded->withFailure('gone', 1790000005.0);

    expect($initial->status())->toBe('DOWN')
        ->and($loaded->status())->toBe('UP')
        ->and($loaded->lastRefresh)->toBe('2026-09-21T14:13:20Z')
        ->and($loaded->flags)->toBe(1)
        ->and($stale->status())->toBe('STALE')
        ->and($stale->document()->toJson())->toContain('"e":{}')
        ->and(SourceState::fromArray($stale->toArray()))->toEqual($stale)
        ->and(SourceState::fromArray('garbage'))->toBeNull();
});

it('counts a successful check that found nothing new as a refresh, and clears the last error', function (): void {
    $stale = SourceState::initial('http')
        ->withLoaded(FlagDocument::fromJson('{"flags":{}}'), 'etag-1', 1790000000.0)
        ->withFailure('timeout', 1790000030.0);
    $recovered = $stale->withUnchanged(1790000060.0);

    expect($stale->lastRefresh)->toBe('2026-09-21T14:13:20Z')
        ->and($recovered->status())->toBe('UP')
        ->and($recovered->error)->toBeNull()
        ->and($recovered->revision)->toBe('etag-1')
        ->and($recovered->checkedAt)->toBe(1790000060.0)
        ->and($recovered->lastRefresh)->toBe('2026-09-21T14:14:20Z');
});

it('reads a failure before any load as DOWN with its error, and an empty document when none was loaded', function (): void {
    $down = SourceState::initial('store')->withFailure('no such table', 1790000000.0);

    expect($down->status())->toBe('DOWN')
        ->and($down->error)->toBe('no such table')
        ->and($down->lastRefresh)->toBeNull()
        ->and($down->document()->flags)->toBe([]);
});

it('reads back only a well-formed cache entry', function (mixed $data): void {
    expect(SourceState::fromArray($data))->toBeNull();
})->with([
    'not an array' => ['garbage'],
    'no name' => [['loaded' => true]],
    'a name that is not text' => [['name' => 7]],
    'loaded without a document' => [['name' => 'file', 'loaded' => true, 'document' => null]],
]);
