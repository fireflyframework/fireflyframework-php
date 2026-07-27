<?php

declare(strict_types=1);

namespace Firefly\Testing\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Marks an @group integration test skipped when Docker is unavailable, so the testcontainers backends are
 * opt-in and never break a Docker-less CI. Use in a FireflyTestCase subclass: call skipUnlessDocker() in setUp.
 *
 * @mixin TestCase
 */
trait RequiresDocker
{
    protected function skipUnlessDocker(): void
    {
        if (! is_docker_available()) {
            $this->markTestSkipped('Docker is not available; skipping @group integration test.');
        }
    }
}
