<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\EventSourcing\Exception\CheckpointConflictException;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Projection\CheckpointStore;
use RuntimeException;

/** What every CheckpointStore owes the ProjectionRunner: memory and SQLite here, every server in the integration lane. */
final class CheckpointStoreContract
{
    /** @return array<string, Closure(CheckpointStore): void> */
    public static function cases(): array
    {
        return [
            'starts a projection it has never seen at position 0' => static function (CheckpointStore $store): void {
                $checkpoint = $store->get('orders.totals');

                expect([$checkpoint->projection, $checkpoint->position, $checkpoint->failed()])->toBe(['orders.totals', 0, false])
                    ->and($store->all())->toBe([]);
            },
            'advances only from the position it was read at' => static function (CheckpointStore $store): void {
                expect($store->advance('p', 0, 5))->toBeTrue()
                    ->and($store->advance('p', 0, 7))->toBeFalse()
                    ->and($store->get('p')->position)->toBe(5)
                    ->and($store->advance('p', 5, 9))->toBeTrue()
                    ->and($store->advance('q', 0, 3))->toBeTrue()
                    ->and($store->get('p')->position)->toBe(9)
                    ->and(array_keys($store->all()))->toBe(['p', 'q'])
                    ->and($store->all()['q']->position)->toBe(3);
            },
            'records a failure without moving, and clears it on the next advance' => static function (CheckpointStore $store): void {
                $store->advance('p', 0, 4);
                $store->recordFailure('p', 'RuntimeException: the read model is down', new DateTimeImmutable('2026-10-13 10:00:00', new DateTimeZone('UTC')));
                $failed = $store->get('p');
                $store->recordFailure('never-ran', 'boom', new DateTimeImmutable('2026-10-13 10:00:00', new DateTimeZone('UTC')));

                expect([$failed->position, $failed->lastError, $failed->failed()])->toBe([4, 'RuntimeException: the read model is down', true])
                    ->and($failed->failedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-13 10:00:00')
                    ->and([$store->get('never-ran')->position, $store->get('never-ran')->failed()])->toBe([0, true])
                    ->and($store->advance('p', 4, 6))->toBeTrue()
                    ->and([$store->get('p')->position, $store->get('p')->lastError, $store->get('p')->failedAt])->toBe([6, null, null]);
            },
            'resets to a position, creating the row when it has to' => static function (CheckpointStore $store): void {
                $store->advance('p', 0, 9);
                $store->reset('p');
                $store->reset('fresh', 3);

                expect($store->get('p')->position)->toBe(0)
                    ->and($store->get('fresh')->position)->toBe(3)
                    ->and($store->advance('p', 0, 2))->toBeTrue()
                    ->and($store->advance('fresh', 3, 4))->toBeTrue();
            },
            'undoes every advance of a transaction that throws, and hands back what the work returned' => static function (CheckpointStore $store): void {
                $store->advance('p', 0, 3);
                try {
                    $store->transactional(static function () use ($store): void {
                        $store->advance('p', 3, 8);

                        throw new RuntimeException('the batch failed');
                    });
                } catch (RuntimeException) {
                }

                expect($store->get('p')->position)->toBe(3)
                    ->and($store->transactional(static fn (): int => 42))->toBe(42);
            },
            'refuses a checkpoint that would move backwards or stand still' => static function (CheckpointStore $store): void {
                expect(fn () => $store->advance('p', 4, 4))->toThrow(EventSourcingException::class, 'only moves forward')
                    ->and($store->isHealthy())->toBeTrue();
            },
        ];
    }

    /**
     * The fence under a real race: `$store` reads inside transactional(), `$rival` (a second connection on a server,
     * the same store where there is one writer) advances and commits, then `$store` advances late, the way the
     * ProjectionRunner does. The late advance is fenced, as `false` or as a CheckpointConflictException where the
     * database itself aborted the transaction, and either way nothing of the late batch survives.
     *
     * @return array<string, Closure(CheckpointStore, CheckpointStore): void>
     */
    public static function races(): array
    {
        return [
            'fences a late first advance after a rival created the row' => static function (CheckpointStore $store, CheckpointStore $rival): void {
                expect(self::raceLate($store, $rival, 'p', 3, 4))->toBe('fenced')
                    ->and($rival->get('p')->position)->toBe(3)
                    ->and($store->advance('p', 3, 5))->toBeTrue()
                    ->and($rival->get('p')->position)->toBe(5);
            },
            'fences a late advance after a rival moved an existing row' => static function (CheckpointStore $store, CheckpointStore $rival): void {
                expect($store->advance('p', 0, 5))->toBeTrue()
                    ->and(self::raceLate($store, $rival, 'p', 7, 6))->toBe('fenced')
                    ->and($rival->get('p')->position)->toBe(7)
                    ->and($store->advance('p', 7, 9))->toBeTrue()
                    ->and($rival->get('p')->position)->toBe(9);
            },
        ];
    }

    /** Reads `$projection` in a transaction, lets `$rival` move it to `$rivalTo` and commit, then advances it to `$lateTo`. */
    private static function raceLate(CheckpointStore $store, CheckpointStore $rival, string $projection, int $rivalTo, int $lateTo): string
    {
        try {
            $store->transactional(static function () use ($store, $rival, $projection, $rivalTo, $lateTo): void {
                $read = $store->get($projection)->position;
                expect($rival->advance($projection, $read, $rivalTo))->toBeTrue();
                if (! $store->advance($projection, $read, $lateTo)) {
                    throw CheckpointConflictException::of($projection, $read);
                }
            });

            return 'committed';
        } catch (CheckpointConflictException) {
            return 'fenced';
        }
    }
}
