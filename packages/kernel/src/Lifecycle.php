<?php

declare(strict_types=1);

namespace Firefly\Kernel;

/**
 * Unified start/stop contract for infrastructure components (DB pools, cache
 * clients, message brokers, HTTP clients). The application context drives
 * start() at boot (fail-fast) and stop() in reverse order at shutdown.
 */
interface Lifecycle
{
    public function start(): void;

    public function stop(): void;
}
