<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Closure;
use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;

/**
 * The behaviour every ExecutionPersistenceProvider owes the engines, written once and run against memory,
 * cache and SQLite in the default lane and against PostgreSQL, MySQL, MariaDB and SQL Server in the
 * integration lane (Task 15). A provider that passes these is interchangeable by configuration alone.
 */
final class ExecutionPersistenceContract
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function state(string $id, ExecutionPattern $pattern = ExecutionPattern::Saga, ExecutionStatus $status = ExecutionStatus::Running, string $updatedAt = '2026-10-12T10:00:00+00:00', array $overrides = []): ExecutionState
    {
        return ExecutionState::fromArray(array_merge([
            'correlationId' => $id,
            'executionName' => 'order',
            'pattern' => $pattern->value,
            'status' => $status->value,
            'startedAt' => '2026-10-12T07:00:00.000000+00:00',
            'updatedAt' => ExecutionState::instant(new DateTimeImmutable($updatedAt)),
            'completedAt' => $status->isTerminal() ? ExecutionState::instant(new DateTimeImmutable($updatedAt)) : null,
            'input' => ['orderId' => $id, 'amount' => 10.0, 'note' => 'café'],
            'stepResults' => ['reserve' => ['hold' => 'h-'.$id]],
            'stepStatuses' => ['reserve' => 'DONE'],
        ], $overrides));
    }

    /**
     * @param  list<ExecutionState>  $states
     * @return list<string>
     */
    public static function ids(array $states, bool $sorted = false): array
    {
        $ids = array_map(static fn (ExecutionState $state): string => $state->correlationId, $states);
        if ($sorted) {
            sort($ids);
        }

        return $ids;
    }

    /**
     * @return array<string, Closure(ExecutionPersistenceProvider): void>
     */
    public static function cases(): array
    {
        return [
            'saves, finds and replaces an execution' => static function (ExecutionPersistenceProvider $provider): void {
                $state = self::state('c-1', overrides: [
                    'searchAttributes' => ['customer' => 'ada'],
                    'timers' => ['t' => '2026-10-12T11:00:00.000000+00:00'],
                    'report' => ['ok' => true, 'steps' => ['reserve' => ['attempts' => 1]]],
                ]);
                $provider->save($state);

                expect($provider->findById('c-1')?->toArray())->toBe($state->toArray())
                    ->and($provider->findById('absent'))->toBeNull();

                $provider->save($state->withStatus(ExecutionStatus::Completed, new DateTimeImmutable('2026-10-12T10:05:00+00:00')));

                expect($provider->findById('c-1')?->status)->toBe(ExecutionStatus::Completed)
                    ->and($provider->find(new ExecutionCriteria))->toHaveCount(1);
            },
            'lists by criteria, newest update first, limited' => static function (ExecutionPersistenceProvider $provider): void {
                $provider->save(self::state('c-a', ExecutionPattern::Saga, ExecutionStatus::Running, '2026-10-12T10:00:00+00:00'));
                $provider->save(self::state('c-b', ExecutionPattern::Saga, ExecutionStatus::Failed, '2026-10-12T10:02:00+00:00', ['executionName' => 'other']));
                $provider->save(self::state('c-c', ExecutionPattern::Tcc, ExecutionStatus::Confirmed, '2026-10-12T10:01:00+00:00'));
                $provider->save(self::state('c-d', ExecutionPattern::Workflow, ExecutionStatus::Waiting, '2026-10-12T10:03:00+00:00'));

                expect(self::ids($provider->find(new ExecutionCriteria)))->toBe(['c-d', 'c-b', 'c-c', 'c-a'])
                    ->and(self::ids($provider->find(new ExecutionCriteria(pattern: ExecutionPattern::Saga))))->toBe(['c-b', 'c-a'])
                    ->and(self::ids($provider->find(new ExecutionCriteria(status: ExecutionStatus::Failed))))->toBe(['c-b'])
                    ->and(self::ids($provider->find(new ExecutionCriteria(name: 'other'))))->toBe(['c-b'])
                    ->and(self::ids($provider->find(new ExecutionCriteria(limit: 2))))->toBe(['c-d', 'c-b']);
            },
            'tells in-flight, stale and due executions apart' => static function (ExecutionPersistenceProvider $provider): void {
                $provider->save(self::state('a', ExecutionPattern::Saga, ExecutionStatus::Running, '2026-10-12T09:00:00+00:00'));
                $provider->save(self::state('b', ExecutionPattern::Workflow, ExecutionStatus::Waiting, '2026-10-12T09:00:00+00:00', ['wakeAt' => '2026-10-12T10:30:00.000000+00:00']));
                $provider->save(self::state('c', ExecutionPattern::Saga, ExecutionStatus::Completed, '2026-10-12T08:00:00+00:00'));
                $provider->save(self::state('d', ExecutionPattern::Saga, ExecutionStatus::Compensating, '2026-10-12T10:00:00+00:00'));

                expect(self::ids($provider->findInFlight(), sorted: true))->toBe(['a', 'b', 'd'])
                    ->and(self::ids($provider->findStale(new DateTimeImmutable('2026-10-12T09:30:00+00:00'))))->toBe(['a'])
                    ->and($provider->findDue(new DateTimeImmutable('2026-10-12T10:29:59+00:00')))->toBe([])
                    ->and(self::ids($provider->findDue(new DateTimeImmutable('2026-10-12T10:30:00+00:00'))))->toBe(['b'])
                    ->and($provider->countInFlight())->toBe(3);

                $counts = $provider->countByStatus();
                ksort($counts);
                expect($counts)->toBe(['COMPENSATING' => 1, 'COMPLETED' => 1, 'RUNNING' => 1, 'WAITING' => 1]);
            },
            'finds by search attributes in their canonical string form' => static function (ExecutionPersistenceProvider $provider): void {
                $provider->save(self::state('x', ExecutionPattern::Workflow, overrides: ['searchAttributes' => ['customer' => 'ada', 'vip' => true]]));
                $provider->save(self::state('y', ExecutionPattern::Workflow, overrides: ['searchAttributes' => ['customer' => 'grace', 'vip' => true]]));
                $provider->save(self::state('z', ExecutionPattern::Workflow));

                expect(self::ids($provider->findBySearchAttributes(['customer' => 'ada'])))->toBe(['x'])
                    ->and($provider->findBySearchAttributes(['customer' => 'ADA']))->toBe([])
                    ->and(self::ids($provider->findBySearchAttributes(['vip' => true]), sorted: true))->toBe(['x', 'y'])
                    ->and(self::ids($provider->findBySearchAttributes(['vip' => 'true', 'customer' => 'grace'])))->toBe(['y']);

                $provider->save(self::state('x', ExecutionPattern::Workflow, overrides: ['searchAttributes' => ['customer' => 'alan']]));

                expect($provider->findBySearchAttributes(['customer' => 'ada']))->toBe([])
                    ->and(self::ids($provider->findBySearchAttributes(['customer' => 'alan'])))->toBe(['x']);
            },
            'updates status, deletes and cleans up terminal executions' => static function (ExecutionPersistenceProvider $provider): void {
                $provider->save(self::state('a', ExecutionPattern::Saga, ExecutionStatus::Completed, '2026-10-12T08:00:00+00:00'));
                $provider->save(self::state('b', ExecutionPattern::Saga, ExecutionStatus::Failed, '2026-10-12T09:00:00+00:00'));
                $provider->save(self::state('c', ExecutionPattern::Workflow, ExecutionStatus::Running, '2026-10-12T07:00:00+00:00', ['searchAttributes' => ['k' => 'v']]));

                $provider->updateStatus('c', ExecutionStatus::Suspended, new DateTimeImmutable('2026-10-12T10:00:00+00:00'));

                $suspended = $provider->findById('c');

                expect($suspended?->status)->toBe(ExecutionStatus::Suspended)
                    ->and($suspended === null ? null : ExecutionState::instant($suspended->updatedAt))->toBe('2026-10-12T10:00:00.000000+00:00')
                    ->and($provider->cleanup(new DateTimeImmutable('2026-10-12T08:30:00+00:00')))->toBe(1)
                    ->and($provider->findById('a'))->toBeNull()
                    ->and($provider->findById('b'))->not->toBeNull()
                    ->and($provider->delete('c'))->toBeTrue()
                    ->and($provider->delete('c'))->toBeFalse()
                    ->and($provider->findBySearchAttributes(['k' => 'v']))->toBe([]);
            },
            'reports its health' => static function (ExecutionPersistenceProvider $provider): void {
                expect($provider->isHealthy())->toBeTrue();
            },
        ];
    }
}
