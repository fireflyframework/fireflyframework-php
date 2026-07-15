<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Lifecycle\PreDestroy;

/**
 * A ContextScannerTest fixture proving #[PostConstruct]/#[PreDestroy] method names are captured
 * onto ContextDescriptor without any behaviour ever being invoked at scan time.
 */
final class LifecycleFixtureWidget
{
    #[PostConstruct]
    public function init(): void {}

    #[PreDestroy]
    public function shutdown(): void {}
}
