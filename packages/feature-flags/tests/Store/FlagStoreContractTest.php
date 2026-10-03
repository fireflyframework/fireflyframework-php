<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Store\FlagChange;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use Firefly\FeatureFlags\Tests\Support\FlagStores;

/** @return array<array-key, mixed> */
function featureFlagsStoredDefinition(string $default = 'off'): array
{
    return Json::members(Json::decode(Json::canonical(Json::decode('{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"'.$default.'"}'))));
}

it('inserts, versions, audits and deletes atomically', function (Closure $driver): void {
    /** @var FlagStore $store */
    $store = $driver();
    expect($store->all())->toBe([])->and($store->revision())->toBe(0);
    $first = $store->put('k', featureFlagsStoredDefinition(), 'ada', 0);
    $second = $store->put('k', featureFlagsStoredDefinition('on'), 'bob', 1);
    expect([$first->id, $first->action, $first->previous])->toBe([1, FlagChange::PUT, null])
        ->and($second->previous)->toBe(featureFlagsStoredDefinition())
        ->and($store->get('k')?->version)->toBe(2)
        ->and($store->revision())->toBe(2);
    $deleted = $store->delete('k', 'bob', 2);
    expect($deleted?->previous)->toBe(featureFlagsStoredDefinition('on'))
        ->and($store->get('k'))->toBeNull()
        ->and($store->delete('k', 'bob'))->toBeNull()
        ->and($store->revision())->toBe(3)
        ->and(array_map(static fn (FlagChange $change): int => $change->id, $store->history('k', 2)))->toBe([3, 2]);
})->with(FlagStores::drivers());

it('rejects stale versions without changing rows or audit', function (Closure $driver): void {
    /** @var FlagStore $store */
    $store = $driver();
    $store->put('k', featureFlagsStoredDefinition(), 'ada');
    expect(fn () => $store->put('k', featureFlagsStoredDefinition('on'), 'eve', 0))->toThrow(FlagStoreConflict::class)
        ->and(fn () => $store->delete('missing', 'eve', 1))->toThrow(FlagStoreConflict::class, 'version none')
        ->and(fn () => $store->put('k', featureFlagsStoredDefinition('on'), 'eve', -1))->toThrow(FlagStoreConflict::class)
        ->and($store->get('k')?->version)->toBe(1)
        ->and($store->revision())->toBe(1);
})->with(FlagStores::drivers());

it('leaves memory untouched when its clock throws during put or delete', function (): void {
    $clockState = new class
    {
        public int $calls = 0;
    };
    $store = new MemoryFlagStore(static function () use ($clockState): DateTimeImmutable {
        if (++$clockState->calls > 1) {
            throw new RuntimeException('clock failed');
        }

        return new DateTimeImmutable('2026-10-01 12:00:00 UTC');
    });
    $store->put('k', featureFlagsStoredDefinition(), 'ada');
    expect(fn () => $store->put('k', featureFlagsStoredDefinition('on'), 'bob'))->toThrow(RuntimeException::class, 'clock failed')
        ->and(fn () => $store->delete('k', 'bob'))->toThrow(RuntimeException::class, 'clock failed')
        ->and($store->get('k')?->version)->toBe(1)
        ->and($store->revision())->toBe(1);
});

it('returns detached definitions and UTC history', function (): void {
    $store = new MemoryFlagStore(static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-01 05:00:00-07:00'));
    $definition = featureFlagsStoredDefinition();
    $definition['targeting'] = (object) ['nested' => (object) []];
    $store->put('k', $definition, 'ada');
    $definition['targeting']->nested->changed = true;
    $stored = $store->get('k');
    $returnedTargeting = Json::members($stored?->definition['targeting'] ?? null);
    $nested = $returnedTargeting['nested'] ?? null;
    if ($nested instanceof stdClass) {
        $nested->changed = true;
    }
    expect(Json::encode($store->get('k')?->definition['targeting']))->toBe('{"nested":{}}')
        ->and($store->history('k')[0]->toHistoryRow()['changedAt'])->toBe('2026-10-01T12:00:00Z');
});
