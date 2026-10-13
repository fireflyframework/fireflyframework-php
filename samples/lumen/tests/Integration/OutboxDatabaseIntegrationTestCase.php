<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Lumen\Tests\Outbox\OutboxDatabaseTestCase;

/**
 * The portable-outbox harness (OutboxDatabaseTestCase) booted on the real server the dataset names. Nothing else
 * differs: the same providers, the same firefly.eda.provider=database, the same drop-then-create of the outbox.
 */
abstract class OutboxDatabaseIntegrationTestCase extends OutboxDatabaseTestCase
{
    use BootsLumenOnIntegrationServer;
}
