<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\Orchestration\Tests\Support\BootsOnIntegrationServer;

/** The event-sourced orchestration capstone booted against the REAL server the test's dataset names. */
abstract class EventSourcedOrchestrationIntegrationTestCase extends EventSourcedOrchestrationCapstoneTestCase
{
    use BootsOnIntegrationServer;
}
