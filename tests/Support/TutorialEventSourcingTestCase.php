<?php

declare(strict_types=1);

namespace Firefly\Tests\Support;

use Firefly\EventSourcing\EventSourcingWiringProvider;
use Firefly\Testing\Concerns\UsesSqliteMemory;
use Illuminate\Support\Facades\Artisan;

/**
 * The tutorial harness after Step 14: FIREFLY_EVENTSOURCING_STORE=database on sqlite :memory:, and `php artisan
 * migrate` as the reader runs it — the package's eventsourcing tables migration (loaded while the store is
 * `database`) plus the reader's own greeting_counts migration from tests/Fixtures/Tutorial/database/migrations.
 */
abstract class TutorialEventSourcingTestCase extends TutorialTestCase
{
    use UsesSqliteMemory;

    public static function migrationsPath(): string
    {
        return dirname(__DIR__).'/Fixtures/Tutorial/database/migrations';
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $this->defineSqliteMemory($app);
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eventsourcing.store.provider' => 'database'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', [
            '--path' => [EventSourcingWiringProvider::MIGRATIONS.'/'.EventSourcingWiringProvider::TABLES_MIGRATION, self::migrationsPath()],
            '--realpath' => true,
        ]);
    }
}
