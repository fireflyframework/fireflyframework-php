<?php

declare(strict_types=1);

use Firefly\Orchestration\Persistence\MemoryExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\Support\ExecutionPersistenceContract;

foreach (ExecutionPersistenceContract::cases() as $case => $assert) {
    it("memory persistence {$case}", function () use ($assert): void {
        $assert(new MemoryExecutionPersistenceProvider);
    });
}
