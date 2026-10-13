<?php

declare(strict_types=1);

use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\DatabaseExecutionLog;
use Firefly\Callbacks\Schema\CallbackSchema;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Tests\Support\CallbackDatabases;
use Firefly\Eda\EventEnvelope;

foreach ([
    'PostgreSQL' => ['pgsql', 'FIREFLY_PG_DSN'],
    'MySQL' => ['mysql', 'FIREFLY_MYSQL_DSN'],
    'MariaDB' => ['mysql', 'FIREFLY_MARIADB_DSN'],
    'SQL Server' => ['sqlsrv', 'FIREFLY_SQLSRV_DSN'],
] as $backend => [$driver, $variable]) {
    it("records a large unicode body, updates it in place and filters recent deliveries on {$backend}", function () use ($driver, $variable): void {
        $connection = CallbackDatabases::connection($driver, $variable);
        CallbackSchema::drop($connection->getSchemaBuilder());
        CallbackSchema::create($connection->getSchemaBuilder());
        $log = new DatabaseExecutionLog($connection);
        $subscription = new CallbackSubscription('sub-1', 'orders', 'https://partner.example.com/hooks', ['order.*']);

        try {
            $big = CallbackExecution::pending($subscription, new EventEnvelope('order.created', 'orders', ['note' => str_repeat('é', 40000)], [], 'e-1'), 100);
            $small = CallbackExecution::pending($subscription, new EventEnvelope('order.created', 'orders', [], [], 'e-2'), 101);
            $log->record($big);
            $log->record($small);
            $log->record($big->withOutcome(CallbackStatus::Delivered, 2, 204, null, 105));
            $log->record($big->withOutcome(CallbackStatus::Delivered, 2, 204, null, 105)); // unchanged re-record

            expect($log->find($big->id)?->body)->toBe($big->body)
                ->and($log->find($big->id)?->status)->toBe(CallbackStatus::Delivered)
                ->and($log->find($big->id)?->attempts)->toBe(2)
                ->and($log->find($big->id)?->responseStatus)->toBe(204)
                ->and($log->find($big->id)?->createdAt)->toBe(100)
                ->and($log->find($big->id)?->updatedAt)->toBe(105)
                ->and(array_map(static fn (CallbackExecution $e): string => $e->eventId, $log->recent()))->toBe(['e-1', 'e-2'])
                ->and($log->recent(10, CallbackStatus::Pending))->toHaveCount(1)
                ->and($log->recent(10, null, 'SUB-1'))->toBe([]) // ids are case-sensitive on every driver
                ->and($connection->table(CallbackSchema::EXECUTIONS)->count())->toBe(2);
        } finally {
            CallbackSchema::drop($connection->getSchemaBuilder());
        }
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated database.");

    it("re-records an execution inside a caller's transaction and leaves that transaction usable on {$backend}", function () use ($driver, $variable): void {
        $connection = CallbackDatabases::connection($driver, $variable);
        CallbackSchema::drop($connection->getSchemaBuilder());
        CallbackSchema::create($connection->getSchemaBuilder());
        $log = new DatabaseExecutionLog($connection);
        $subscription = new CallbackSubscription('sub-1', 'orders', 'https://partner.example.com/hooks', ['order.*']);
        $execution = CallbackExecution::pending($subscription, new EventEnvelope('order.created', 'orders', [], [], 'e-1'), 100);

        try {
            $log->record($execution);
            $connection->transaction(function () use ($log, $execution): void {
                // The INSERT hits the primary key; on PostgreSQL that would abort the caller's transaction
                // unless it runs under its own savepoint.
                $log->record($execution->withOutcome(CallbackStatus::Failed, 3, 503, 'HTTP 503', 110));
                $log->record(CallbackExecution::pending(new CallbackSubscription('sub-2', 'orders', 'https://partner.example.com/hooks', ['order.*']), new EventEnvelope('order.created', 'orders', [], [], 'e-1'), 111));
            });

            expect($log->find($execution->id)?->status)->toBe(CallbackStatus::Failed)
                ->and($log->find($execution->id)?->lastError)->toBe('HTTP 503')
                ->and($log->find($execution->id)?->createdAt)->toBe(100)
                ->and($connection->table(CallbackSchema::EXECUTIONS)->count())->toBe(2);
        } finally {
            CallbackSchema::drop($connection->getSchemaBuilder());
        }
    })->group('integration')->skip(getenv($variable) === false, "Set {$variable} to an isolated database.");
}
