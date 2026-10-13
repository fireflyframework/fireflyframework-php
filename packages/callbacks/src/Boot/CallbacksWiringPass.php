<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Boot;

use Firefly\Callbacks\CallbacksSettings;
use Firefly\Callbacks\Delivery\CallbackDispatcher;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;

/**
 * Subscribes the CallbackDispatcher to every pattern in firefly.callbacks.event-types on the EventPublisher, at
 * WiringPasses/30 — the programmatic twin of an #[EventListener], because the patterns are configuration and a
 * framework package is not in the application's compiled listener manifest (spec Decision 14). The dispatcher is
 * resolved per envelope, never captured, so a test or an application can rebind it. Nothing is subscribed while
 * callbacks are off or the list is empty: a process that delivers no callbacks is not a consumer of the bus. On a
 * broker provider, the worker binds the destinations those events are published to through firefly.eda.destinations.
 *
 * The bus calls EVERY handler whose pattern matches, so overlapping patterns (`order.*` beside `order.created`, or
 * `*` beside anything) would hand one envelope to the dispatcher once per match — and a failing delivery would run
 * its whole retry cycle, back-off sleeps and breaker failures again each time. The patterns are therefore
 * deduplicated, and each handler dispatches only when its pattern is the FIRST in the list that matches the
 * envelope, so every event reaches the dispatcher exactly once however the list overlaps.
 */
final class CallbacksWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 30;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(CallbacksSettings::class) || ! $container->bound(EventPublisher::class)) {
            return;
        }

        /** @var CallbacksSettings $settings */
        $settings = $container->make(CallbacksSettings::class);
        if (! $settings->enabled || $settings->eventTypes === []) {
            return;
        }

        /** @var EventPublisher $publisher */
        $publisher = $container->make(EventPublisher::class);
        $patterns = array_values(array_unique($settings->eventTypes));
        foreach ($patterns as $pattern) {
            $publisher->subscribe($pattern, static function (EventEnvelope $envelope) use ($container, $patterns, $pattern): void {
                if (self::firstMatch($patterns, $envelope->eventType) !== $pattern) {
                    return;
                }

                $container->make(CallbackDispatcher::class)->dispatch($envelope);
            });
        }
    }

    /**
     * The first pattern that matches the event type, with the bus's own fnmatch() semantics, or null when none does.
     *
     * @param  list<string>  $patterns
     */
    private static function firstMatch(array $patterns, string $eventType): ?string
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $eventType)) {
                return $pattern;
            }
        }

        return null;
    }
}
