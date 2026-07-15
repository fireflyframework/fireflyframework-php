<?php

declare(strict_types=1);

namespace Firefly\Context\Processor;

use Firefly\Context\Lifecycle\InitDestroyInvoker;
use WeakMap;

/**
 * Runs the two-pass BeanPostProcessor pipeline for ONE bean at a time:
 *
 *   foreach BPP: $bean = $bpp->beforeInitialization($bean, $declaredClass)
 *   invoke #[PostConstruct] on $bean
 *   foreach BPP: $bean = $bpp->afterInitialization($bean, $declaredClass)
 *
 * This is per-bean across ALL BPPs, NOT a global two-sweep over every bean (all-beans x
 * beforeInit, then all-beans x afterInit + #[PostConstruct]) — the latter reading would require
 * resolving every bean up front, which the boot pipeline never does. #[PostConstruct] runs
 * strictly BETWEEN the two passes, as in Spring's initializeBean().
 *
 * The ordered BPP list is FROZEN at construction and never re-read or re-sorted. Exactly ONE
 * composite Illuminate extender per abstract delegates to a chain instance like this one
 * (installed by a later dispatch); extenders fire in *registration* order, so if each BPP
 * installed its own extender instead, a later-discovered BPP would silently append at the tail
 * and defeat #[Order]. Freezing the order here, once, at construction, is what keeps #[Order]
 * honest — this class never derives ordering from anything but the list it was built with.
 *
 * $declaredClass must be the DECLARED class from the bean's definition, threaded through
 * verbatim — never recomputed from $bean::class. A bean wrapped into a proxy by
 * afterInitialization() has a ::class that is the proxy's, which has no manifest entry; by
 * construction, #[PostConstruct] above always runs before any BPP has had the chance to wrap the
 * bean, so it always sees the correct declared class for the pre-proxy instance.
 */
final class BeanPostProcessorChain
{
    /**
     * Keyed by the ORIGINAL (pre-chain) bean instance, valued by the fully-processed result.
     * Defense-in-depth against a bean being run through the chain twice (e.g. an extender firing
     * again for a reason the type system can't rule out) — a plain array or SplObjectStorage
     * would hold a STRONG reference to every processed bean, pinning it in memory for the
     * lifetime of the chain (effectively forever under Octane). A WeakMap does not.
     *
     * @var WeakMap<object, object>
     */
    private WeakMap $done;

    /**
     * @param  list<BeanPostProcessor>  $ordered  already #[Order]-sorted by the caller; taken
     *                                            verbatim and never re-sorted or re-read
     */
    public function __construct(
        private readonly array $ordered,
        private readonly InitDestroyInvoker $invoker,
    ) {
        $this->done = new WeakMap;
    }

    /**
     * @param  class-string  $declaredClass
     */
    public function process(object $bean, string $declaredClass): object
    {
        if ($this->done->offsetExists($bean)) {
            return $this->done[$bean];
        }

        $original = $bean;

        foreach ($this->ordered as $processor) {
            $bean = $processor->beforeInitialization($bean, $declaredClass);
        }

        $this->invoker->invokeInit($bean, $declaredClass);

        foreach ($this->ordered as $processor) {
            $bean = $processor->afterInitialization($bean, $declaredClass);
        }

        $this->done[$original] = $bean;

        return $bean;
    }
}
