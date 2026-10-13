<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\CapstoneFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\OrchestrationEventsAdapter;

/** An application hook: discovered by the composite because it is a #[Component] implementing OrchestrationEvents. */
#[Component]
final class CheckoutHook extends OrchestrationEventsAdapter
{
    public function __construct(private readonly Ledger $ledger) {}

    public function onStart(string $name, string $correlationId, ExecutionPattern $pattern): void
    {
        $this->ledger->record("hook start {$name}");
    }
}
