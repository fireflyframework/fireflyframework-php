<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

/**
 * The queued-workflow capstone booted against a REAL server (see BootsOnIntegrationServer): the execution
 * checkpoints, the search attributes AND the `database` queue's jobs table all live on the server the test's
 * dataset names, exactly as in a deployed application whose worker shares the database.
 */
abstract class QueuedWorkflowIntegrationTestCase extends QueuedWorkflowCapstoneTestCase
{
    use BootsOnIntegrationServer;
}
