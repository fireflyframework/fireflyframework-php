<?php

declare(strict_types=1);

use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Projection\DatabaseCheckpointStore;
use Firefly\EventSourcing\Projection\MemoryCheckpointStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Tests\Support\CheckpointStoreContract;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Illuminate\Database\Schema\Blueprint;

foreach (CheckpointStoreContract::cases() as $case => $assert) {
    it("memory checkpoint store {$case}", function () use ($assert): void {
        $assert(new MemoryCheckpointStore);
    });

    it("SQLite checkpoint store {$case}", function () use ($assert): void {
        $assert(new DatabaseCheckpointStore(EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite())));
    });
}

it('rolls the rows a projection wrote back with the advance of its batch, on the checkpoint connection', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $connection->getSchemaBuilder()->create('es_test_rows', static function (Blueprint $table): void {
        $table->string('value');
    });
    $store = new DatabaseCheckpointStore($connection);

    try {
        $store->transactional(static function () use ($connection, $store): void {
            $connection->table('es_test_rows')->insert(['value' => 'projected']);
            $store->advance('p', 0, 1);

            throw new RuntimeException('the batch failed');
        });
    } catch (RuntimeException) {
    }

    expect($connection->table('es_test_rows')->count())->toBe(0)
        ->and($connection->table(EventSourcingSchema::CHECKPOINTS)->count())->toBe(0);
});

it('refuses, before any write, a projection name longer than its column', function (): void {
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());

    expect(fn () => (new DatabaseCheckpointStore($connection))->advance(str_repeat('p', 192), 0, 1))
        ->toThrow(EventSourcingException::class, 'projection')
        ->and($connection->table(EventSourcingSchema::CHECKPOINTS)->count())->toBe(0);
});
