<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * A ContextScannerTest fixture proving an explicit #[AsEventListener(event: ..., order: ...)] is
 * captured verbatim — no inference needed when $event is already given.
 */
final class ExplicitListenerWidget
{
    #[AsEventListener(event: ListenerEvent::class, order: 5)]
    public function onEvent(mixed $event): void {}
}
