<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

use Firefly\Context\Lifecycle\PostConstruct;

/**
 * A ContextScannerTest fixture proving the abstract-class guard (mirroring
 * ConfigPropertiesScanner's guard) fires even for a class that WOULD otherwise contribute a
 * descriptor — an abstract class can never be resolved to a single instance to run lifecycle
 * callbacks against, so it must never appear in the manifest.
 */
abstract class AbstractLifecycleFixture
{
    #[PostConstruct]
    public function init(): void {}
}
