<?php

declare(strict_types=1);

namespace Firefly\Context\Octane;

use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Illuminate\Container\Container;

/**
 * Resets per-request container state on ONE container.
 *
 * Deliberately framework-agnostic: no Octane types appear anywhere in this file, so this class is
 * unit-testable without laravel/octane installed at all (see StateResetterTest, which exercises
 * it against a bare Illuminate\Container\Container). OctaneListener is the only class in this
 * package that knows about Octane's event shapes, and the only caller responsible for passing the
 * RIGHT container in — see its docblock for the sandbox-vs-app distinction (INVARIANT 7).
 *
 * INVARIANT 8: Illuminate\Container\Container::forgetScopedInstances() only unsets
 * instances[$type] for every Scope::Scoped binding — it has NO destruction callback of its own, so
 * anything not drained first is silently and permanently dropped (a scoped bean holding a DB
 * transaction or a file handle would leak it). So the order here is fixed: drain
 * DisposableBeanRegistry's scoped ledger — which invokes #[PreDestroy] on every tracked scoped
 * bean in reverse registration order AND empties the ledger itself — and only THEN call
 * forgetScopedInstances().
 */
final class StateResetter
{
    public function reset(Container $container): void
    {
        if ($container->bound(DisposableBeanRegistry::class)) {
            $container->make(DisposableBeanRegistry::class)->drainScoped();
        }

        $container->forgetScopedInstances();
    }
}
