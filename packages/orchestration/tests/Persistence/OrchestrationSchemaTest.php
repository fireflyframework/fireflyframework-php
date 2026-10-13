<?php

declare(strict_types=1);

use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Tests\Support\IntegrationDatabase;
use Illuminate\Database\SqlServerConnection;

it('creates the three tables idempotently and drops them', function (): void {
    $connection = IntegrationDatabase::sqlite();
    $schema = $connection->getSchemaBuilder();

    OrchestrationSchema::create($schema);
    OrchestrationSchema::create($schema);

    expect($schema->hasTable(OrchestrationSchema::EXECUTIONS))->toBeTrue()
        ->and($schema->hasColumns(OrchestrationSchema::EXECUTIONS, ['correlation_id', 'execution_name', 'pattern', 'status', 'parent_correlation_id', 'started_at', 'updated_at', 'completed_at', 'wake_at', 'payload']))->toBeTrue()
        ->and($schema->hasColumns(OrchestrationSchema::SEARCH_ATTRIBUTES, ['correlation_id', 'attribute_key', 'attribute_value']))->toBeTrue()
        ->and($schema->hasColumns(OrchestrationSchema::DEAD_LETTERS, ['id', 'execution_name', 'correlation_id', 'pattern', 'step_id', 'status_at_failure', 'error_message', 'error_type', 'input', 'headers', 'retry_count', 'created_at', 'last_retried_at']))->toBeTrue()
        ->and(OrchestrationSchema::instant(new DateTimeImmutable('2026-10-12T12:00:00.5+02:00')))->toBe('2026-10-12 10:00:00.500000');

    OrchestrationSchema::drop($schema);

    expect($schema->hasTable(OrchestrationSchema::EXECUTIONS))->toBeFalse();
});

it('gives SQL Server the binary collation MySQL and MariaDB get, so ids and search attributes compare case-sensitively', function (): void {
    $connection = new SqlServerConnection(static fn (): never => throw new RuntimeException('No server: the DDL is only pretended.'), 'orchestration', '', ['driver' => 'sqlsrv', 'name' => 'orchestration-sqlsrv']);

    $ddl = implode("\n", array_column($connection->pretend(static function () use ($connection): void {
        OrchestrationSchema::create($connection->getSchemaBuilder());
    }), 'query'));

    expect($ddl)->toContain('"correlation_id" nvarchar(64) collate Latin1_General_100_BIN2 not null, "execution_name" nvarchar(191) collate Latin1_General_100_BIN2 not null')
        ->and($ddl)->toContain('"attribute_key" nvarchar(128) collate Latin1_General_100_BIN2 not null, "attribute_value" nvarchar(191) collate Latin1_General_100_BIN2 not null')
        ->and($ddl)->toContain('"id" nvarchar(64) collate Latin1_General_100_BIN2 not null')
        ->and($ddl)->toContain('"pattern" nvarchar(16) not null');
});
