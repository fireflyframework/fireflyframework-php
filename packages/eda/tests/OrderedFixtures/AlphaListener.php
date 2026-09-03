<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\OrderedFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

/**
 * Ordering fixture A of three. The three classes in this directory are deliberately named so that their
 * ALPHABETICAL order (Alpha, Beta, Gamma — which is the order EventListenerScanner emits them in, because
 * EventListenerScanner::classes() sort()s the discovered FQCNs) is the exact REVERSE-ish of their declared
 * #[EventListener(order:)] values (30, 10, 20). A dispatch that honours the declared order must therefore run
 * beta -> gamma -> alpha; a dispatch that merely replays the compiled-manifest order runs alpha -> beta -> gamma.
 * That gap is what EventListenerOrderingTest asserts on, and it is what the pre-fix wiring pass got wrong.
 *
 * They live in their OWN directory rather than tests/Fixtures because several existing suites scan tests/Fixtures
 * with the real scanner and assert on the exact set of listeners it finds (EventListenerWiringPassTest expects
 * `['order.placed']` and nothing else); adding listeners there would have broken those assertions for reasons
 * unrelated to ordering.
 */
#[Component]
final class AlphaListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('ordered.*', order: 30)]
    public function onOrdered(EventEnvelope $envelope): void
    {
        $this->spy->record('alpha');
    }
}
