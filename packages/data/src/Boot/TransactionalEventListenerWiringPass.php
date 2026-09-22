<?php

declare(strict_types=1);

namespace Firefly\Data\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Data\DataSettings;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionPhase;
use Firefly\Data\Transaction\TransactionSynchronizationRegistry;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Registers every compiled #[TransactionalEventListener] on Illuminate's dispatcher — the transaction-aware
 * twin of RegisterEventListenersPass, in the same phase, ten places later (so the context's plain listeners
 * are already there; #[Order] applies among transactional listeners, not across the two kinds).
 *
 * Each closure is DispatcherEventPublisher::guardListener()-wrapped (a listener returning false must never
 * starve the ones after it — the context's blocking requirement, honoured here too) and resolves its bean
 * FRESH on every dispatch, so it observes the fully post-processed, possibly proxied, form. At dispatch it
 * asks the TransactionSynchronizationRegistry whether a transaction is active on the current connection:
 * yes — the invocation is queued for the listener's phase; no — it runs at once if fallbackExecution, else
 * it is skipped. That is Spring's contract to the letter.
 *
 * Reads the compiled manifest from the container (the app's transactional.php via DataAutoConfiguration, or an
 * inline scan, or a test's competing bean) — never a scan of its own. Off entirely under
 * firefly.data.transactional-event-listeners.enabled=false.
 */
final class TransactionalEventListenerWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::EventListeners;
    }

    public function order(): int
    {
        return 10;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var DataSettings $settings */
        $settings = $container->bound(DataSettings::class) ? $container->make(DataSettings::class) : DataSettings::fromConfig($context->config);
        if (! $settings->transactionalEventListeners || ! $container->bound(TransactionalManifest::class)) {
            return;
        }

        /** @var TransactionalManifest $manifest */
        $manifest = $container->make(TransactionalManifest::class);
        /** @var Dispatcher $dispatcher */
        $dispatcher = $container->make('events');

        foreach ($manifest->listeners() as $row) {
            $phase = TransactionPhase::from($row['phase']);
            $class = $row['class'];
            $method = $row['method'];
            $fallback = $row['fallbackExecution'];

            $raw = static function (object $event) use ($container, $class, $method, $phase, $fallback): void {
                $invoke = static function () use ($container, $class, $method, $event): void {
                    /** @var object $bean */
                    $bean = $container->make($class);
                    $bean->{$method}($event);
                };

                /** @var TransactionSynchronizationRegistry $registry */
                $registry = $container->make(TransactionSynchronizationRegistry::class);

                if ($registry->isTransactionActive()) {
                    $registry->register($phase, $invoke);
                } elseif ($fallback) {
                    $invoke();
                }
            };

            $dispatcher->listen($row['event'], DispatcherEventPublisher::guardListener($raw));
        }
    }
}
