<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The orchestration capstone with ASYNC workflows routed through a REAL Laravel queue: the `database` queue
 * driver on the application's own connection, `firefly.orchestration.workflow.queue.connection` = `database`
 * and `.name` = `workflows`, while `queue.default` stays `sync` — so a job that reaches the jobs table proves
 * the configured connection was applied, not the application default. Failed jobs are not stored, and the
 * jobs table gets a name of its own so the integration lane never touches another suite's `jobs`.
 */
abstract class QueuedWorkflowCapstoneTestCase extends OrchestrationCapstoneTestCase
{
    public const string JOBS_TABLE = 'firefly_orchestration_it_jobs';

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => null,
                'table' => self::JOBS_TABLE,
                'queue' => 'default',
                'retry_after' => 90,
                'after_commit' => false,
            ],
            'queue.failed.driver' => 'null',
            'firefly.orchestration.workflow.queue.connection' => 'database',
            'firefly.orchestration.workflow.queue.name' => 'workflows',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::createJobsTable(DB::connection()->getSchemaBuilder());
    }

    /** Laravel's own jobs-table shape (the `queue:table` migration), dropped first so a rerun starts empty. */
    public static function createJobsTable(Builder $schema): void
    {
        $schema->dropIfExists(self::JOBS_TABLE);
        $schema->create(self::JOBS_TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    /** Runs ONE job the way a deployed worker does: `queue:work database --queue=workflows --once`. */
    public function work(): int
    {
        return $this->command('queue:work', ['connection' => 'database', '--queue' => 'workflows', '--once' => true, '--stop-when-empty' => true]);
    }
}
