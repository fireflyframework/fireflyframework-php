<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Lumen\Tests\EventSourcing\LumenEventSourcedOrchestrationTestCase;

/** The event-sourced orchestration harness booted on the real server the dataset names. */
abstract class EventSourcedPayoutIntegrationTestCase extends LumenEventSourcedOrchestrationTestCase
{
    use BootsLumenOnIntegrationServer;
}
