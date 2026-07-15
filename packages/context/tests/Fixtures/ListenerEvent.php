<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A plain event payload used by ContextScannerTest to exercise both explicit and
 * inferred #[AsEventListener] event resolution.
 */
final class ListenerEvent {}
