<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

/**
 * The orchestration capstone — attribute-discovered fixtures, the real beans, database persistence and a
 * database DLQ — booted against the REAL server the test's dataset names (see BootsOnIntegrationServer).
 */
abstract class OrchestrationIntegrationTestCase extends OrchestrationCapstoneTestCase
{
    use BootsOnIntegrationServer;
}
