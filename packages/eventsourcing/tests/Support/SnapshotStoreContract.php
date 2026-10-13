<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Closure;
use DateTimeImmutable;
use Firefly\EventSourcing\Snapshot\Snapshot;
use Firefly\EventSourcing\Snapshot\SnapshotStore;

/** What every SnapshotStore owes the repository: memory and SQLite here, every server in the integration lane. */
final class SnapshotStoreContract
{
    /** @param array<string, mixed> $state */
    public static function snapshot(string $id, int $version, array $state = ['total' => 10.0, 'note' => 'café'], int $stateVersion = 1): Snapshot
    {
        return new Snapshot($id, 'order', $version, $state, $stateVersion, new DateTimeImmutable('2026-10-13 10:00:00'));
    }

    /** @return array<string, Closure(SnapshotStore): void> */
    public static function cases(): array
    {
        return [
            'saves a snapshot and loads the latest, its whole floats intact' => static function (SnapshotStore $store): void {
                $store->save(self::snapshot('o-1', 50));
                $store->save(self::snapshot('o-1', 100, ['total' => 20.0, 'lines' => [['sku' => 'A']]]));

                $latest = $store->latest('o-1');

                expect([$latest?->version, $latest?->state, $latest?->aggregateType, $latest?->stateVersion])->toBe([100, ['total' => 20.0, 'lines' => [['sku' => 'A']]], 'order', 1])
                    ->and($store->latest('o-2'))->toBeNull();
            },
            'loads the latest snapshot at or before a version' => static function (SnapshotStore $store): void {
                foreach ([50, 100, 150] as $version) {
                    $store->save(self::snapshot('o-1', $version));
                }

                expect($store->latest('o-1', 149)?->version)->toBe(100)
                    ->and($store->latest('o-1', 150)?->version)->toBe(150)
                    ->and($store->latest('o-1', 49))->toBeNull();
            },
            'replaces a snapshot of the same version' => static function (SnapshotStore $store): void {
                $store->save(self::snapshot('o-1', 50, ['v' => 1]));
                $store->save(self::snapshot('o-1', 50, ['v' => 2], 2));

                expect([$store->latest('o-1')?->state, $store->latest('o-1')?->stateVersion])->toBe([['v' => 2], 2]);
            },
            'prunes to the newest keep-count and deletes an aggregate\'s snapshots' => static function (SnapshotStore $store): void {
                foreach ([50, 100, 150, 200] as $version) {
                    $store->save(self::snapshot('o-1', $version));
                }
                $store->save(self::snapshot('o-2', 50));

                expect($store->prune('o-1', 2))->toBe(2)
                    ->and($store->latest('o-1', 149))->toBeNull()
                    ->and($store->latest('o-1')?->version)->toBe(200)
                    ->and($store->prune('o-1', 2))->toBe(0)
                    ->and($store->delete('o-1'))->toBe(2)
                    ->and($store->latest('o-1'))->toBeNull()
                    ->and($store->latest('o-2')?->version)->toBe(50)
                    ->and($store->isHealthy())->toBeTrue();
            },
        ];
    }
}
