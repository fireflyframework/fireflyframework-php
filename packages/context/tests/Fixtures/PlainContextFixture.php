<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A ContextScannerTest fixture carrying NONE of #[PostConstruct]/#[PreDestroy]/#[AsEventListener]/
 * #[ConditionalOn*] — proves scan() contributes no descriptor at all for it, keeping the manifest
 * sparse (mirrors ConfigPropertiesScannerTest's Pool fixture).
 */
final class PlainContextFixture {}
