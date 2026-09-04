<?php

declare(strict_types=1);

namespace Firefly\Eda\Boot;

use Closure;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\DeadLetter\RetryingEventHandler;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;
use Illuminate\Container\Container;

/**
 * Subscribes the app's compiled #[EventListener]s onto the resolved EventPublisher at phase WiringPasses/1000 (the
 * instance stage). Runs in EVERY process — web request AND queue worker alike — so the share-nothing worker
 * rebuilds the identical SubscriberRegistry from the same manifest, which is what makes the async adapter's
 * worker-side match+invoke correct (see the async-delivery model note). Each subscriber is wrapped by
 * RetryingEventHandler with the config-driven retries/retryDelay + the bound DeadLetterStore, and resolves its
 * target bean FRESH on every dispatch (never caching it at registration) — exactly like RegisterEventListenersPass
 * — so it always observes the fully post-processed (possibly proxied) bean.
 *
 * 🔴 It subscribes in EventListenerManifest::ordered() order, NOT all() order. SubscriberRegistry::deliver()
 * invokes matching handlers in SUBSCRIPTION order, so this loop is the only place the declared
 * #[EventListener(order:)] can be honoured — and before this it looped over all(), which meant the scanned,
 * compiled, round-tripped `order` was read from the manifest and then thrown away. Listeners ran in whatever
 * sequence the scanner emitted (FQCN sort, then declaration order), so an audit/enrichment listener that
 * declared a low order to run first ran wherever its class name sorted, silently. See
 * EventListenerManifest::ordered() for the sort and its stable tie-break.
 */
final class EventListenerWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var EventListenerManifest $manifest */
        $manifest = $container->make(EventListenerManifest::class);
        /** @var EventPublisher $bus */
        $bus = $container->make(EventPublisher::class);
        /** @var DeadLetterStore $dlq */
        $dlq = $container->make(DeadLetterStore::class);

        $retries = $context->config->int('firefly.eda.retries', 0);
        $retryDelayRaw = $context->config->get('firefly.eda.retry_delay', 0.0);
        $retryDelay = is_numeric($retryDelayRaw) ? (float) $retryDelayRaw : 0.0;

        foreach ($manifest->ordered() as $descriptor) {
            $handler = RetryingEventHandler::wrap($this->invoker($container, $descriptor), $retries, $retryDelay, $dlq);

            foreach ($descriptor->patterns as $pattern) {
                $bus->subscribe($pattern, $handler);
            }
        }
    }

    private function invoker(Container $container, EventListenerDescriptor $descriptor): Closure
    {
        return static function (EventEnvelope $envelope) use ($container, $descriptor): void {
            $bean = $container->make($descriptor->class);
            $method = $descriptor->method;
            $bean->{$method}($envelope);
        };
    }
}
