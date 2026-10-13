<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Lumen\Tests\EventSourcing\LumenEventSourcingOutboxTestCase;

/**
 * The event-sourcing + outbox harness (LumenEventSourcingOutboxTestCase) booted on the real server the dataset names.
 * BootsLumenOnIntegrationServer's migrate:fresh wipes the lane database first; the parents then recreate the outbox
 * and the eventsourcing tables on it.
 */
abstract class LoanLedgerIntegrationTestCase extends LumenEventSourcingOutboxTestCase
{
    use BootsLumenOnIntegrationServer;
}
