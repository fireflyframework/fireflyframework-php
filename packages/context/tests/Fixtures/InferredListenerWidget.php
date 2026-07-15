<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * A ContextScannerTest fixture proving a null $event is INFERRED from the listener method's first
 * parameter type — once, at SCAN time (never at boot; see ContextScanner::inferEventType()).
 */
final class InferredListenerWidget
{
    #[AsEventListener]
    public function onEvent(ListenerEvent $event): void {}
}
