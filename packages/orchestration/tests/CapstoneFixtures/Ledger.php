<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Component;

/** What every capstone step did, in order — a singleton component the fixtures inject. */
#[Component]
final class Ledger
{
    /** @var list<string> */
    public array $lines = [];

    public function record(string $line): void
    {
        $this->lines[] = $line;
    }
}
