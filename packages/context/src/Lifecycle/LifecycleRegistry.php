<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Firefly\Kernel\Lifecycle;

/**
 * Tracks Lifecycle components in START order, so ApplicationContext::close() can stop() them in the
 * mirrored REVERSE order — infrastructure that came up last goes down first, the same "dependents
 * before dependencies" rule DisposableBeanRegistry applies to #[PreDestroy].
 *
 * Holds STRONG references deliberately, unlike DisposableBeanRegistry's WeakReferences: these are
 * the actual infrastructure singletons InfrastructureStartPass resolved and started. They are meant
 * to live for the whole application lifetime, so pinning them here is correct, not a leak.
 */
final class LifecycleRegistry
{
    /** @var list<Lifecycle> */
    private array $started = [];

    public function add(Lifecycle $component): void
    {
        $this->started[] = $component;
    }

    /**
     * Stops every tracked component in REVERSE start order. Idempotent: draining empties the
     * ledger, so a second call is a safe no-op.
     */
    public function stopAll(): void
    {
        foreach (array_reverse($this->started) as $component) {
            $component->stop();
        }

        $this->started = [];
    }
}
