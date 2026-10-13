<?php

declare(strict_types=1);

use Firefly\Orchestration\Dlq\MemoryDeadLetterStore;
use Firefly\Orchestration\Tests\Support\DeadLetterStoreContract;

foreach (DeadLetterStoreContract::cases() as $case => $assert) {
    it("memory dead-letter store {$case}", function () use ($assert): void {
        $assert(new MemoryDeadLetterStore);
    });
}
