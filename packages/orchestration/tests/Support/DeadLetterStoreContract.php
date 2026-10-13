<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Closure;
use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Dlq\DeadLetterStore;
use RuntimeException;

/** What every DeadLetterStore owes the service, run against memory, SQLite and every real driver. */
final class DeadLetterStoreContract
{
    public static function entry(string $id, string $name = 'order', string $correlationId = 'c-1', string $createdAt = '2026-10-12T10:00:00+00:00'): DeadLetterEntry
    {
        $entry = DeadLetterEntry::create($name, $correlationId, ExecutionPattern::Saga, 'charge', ExecutionStatus::Failed, new RuntimeException('card declined'), ['orderId' => 7, 'note' => 'café'], ['tenant' => 'acme'], new DateTimeImmutable($createdAt));

        return DeadLetterEntry::fromArray(['id' => $id] + $entry->toArray());
    }

    /**
     * @return array<string, Closure(DeadLetterStore): void>
     */
    public static function cases(): array
    {
        return [
            'saves, finds and replaces an entry' => static function (DeadLetterStore $store): void {
                $entry = self::entry('d-1');
                $store->save($entry);

                expect($store->findById('d-1')?->toArray())->toBe($entry->toArray())
                    ->and($store->findById('absent'))->toBeNull();

                $store->save($entry->withRetry(new DateTimeImmutable('2026-10-12T11:00:00+00:00')));

                expect($store->findById('d-1')?->retryCount)->toBe(1)
                    ->and($store->count())->toBe(1);
            },
            'lists newest first and filters by execution name and correlation id' => static function (DeadLetterStore $store): void {
                $store->save(self::entry('d-1', 'order', 'c-1', '2026-10-12T10:00:00+00:00'));
                $store->save(self::entry('d-2', 'refund', 'c-2', '2026-10-12T10:02:00+00:00'));
                $store->save(self::entry('d-3', 'order', 'c-3', '2026-10-12T10:01:00+00:00'));

                expect(array_map(static fn (DeadLetterEntry $e): string => $e->id, $store->findAll()))->toBe(['d-2', 'd-3', 'd-1'])
                    ->and(array_map(static fn (DeadLetterEntry $e): string => $e->id, $store->findAll(2)))->toBe(['d-2', 'd-3'])
                    ->and(array_map(static fn (DeadLetterEntry $e): string => $e->id, $store->findByExecutionName('order')))->toBe(['d-3', 'd-1'])
                    ->and(array_map(static fn (DeadLetterEntry $e): string => $e->id, $store->findByCorrelationId('c-2')))->toBe(['d-2']);
            },
            'deletes an entry once' => static function (DeadLetterStore $store): void {
                $store->save(self::entry('d-1'));

                expect($store->delete('d-1'))->toBeTrue()
                    ->and($store->delete('d-1'))->toBeFalse()
                    ->and($store->count())->toBe(0);
            },
        ];
    }
}
