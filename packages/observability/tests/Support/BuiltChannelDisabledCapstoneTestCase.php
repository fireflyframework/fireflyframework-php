<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

/**
 * The gate-OFF twin of the built-channel capstone. `firefly.logging.structured.all-channels` is read in
 * ObservabilityWiringProvider::register() — the only moment at which the ContextLogProcessor contract can be
 * rebound before anything resolves `log` — so proving the gate needs its own boot, exactly as
 * ObservabilityDisabledCapstoneTestCase needs one for the metrics gate. Flipping the key from a test body
 * cannot un-bind a contract that was already rebound.
 */
class BuiltChannelDisabledCapstoneTestCase extends ObservabilityTracingCapstoneTestCase
{
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.logging.structured.all-channels' => false,
        ];
    }
}
