<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

/**
 * The http-exchanges-DISABLED sibling of ObservabilityCapstoneTestCase. Metrics stay ON here on purpose: the
 * two features have independent switches, and the thing worth proving is that turning one off leaves the other
 * entirely alone.
 *
 * firefly.observability.httpexchanges.enabled is read by HttpExchangeFilter's #[ConditionalOnProperty] during
 * condition filtering at BOOT, so this needs its own boot — flipping the flag inside a test body cannot
 * un-push middleware the FilterChainRegistrar has already pushed onto the HTTP kernel.
 */
class ObservabilityHttpExchangesDisabledCapstoneTestCase extends ObservabilityCapstoneTestCase
{
    protected function httpExchangesEnabled(): bool
    {
        return false;
    }
}
