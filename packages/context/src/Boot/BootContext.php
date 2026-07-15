<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Container\Container;

/**
 * State threaded through the boot passes.
 *
 * Deliberately NOT bound as a container singleton: boot-time state must not be reachable from
 * request state (it would survive across Octane requests).
 *
 * $contextManifest is the compiled ContextScanner output (#[PostConstruct]/#[PreDestroy] method
 * names, #[AsEventListener] listeners, #[ConditionalOn*] attributes) — the ONE seam through which
 * RegisterEventListenersPass and InitDestroyInvoker read this metadata, so neither reflects a
 * declared class at boot. Defaults to an empty manifest (via "new in initializers") so every
 * existing named-argument BootContext construction site keeps compiling unchanged.
 */
final class BootContext
{
    public function __construct(
        public readonly Container $container,
        public readonly BeanDefinitionRegistry $definitions,
        public readonly Config $config,
        public readonly Profiles $profiles,
        public readonly ConditionEvaluator $conditions,
        public readonly ConditionEvaluationReport $report,
        public readonly ContextManifest $contextManifest = new ContextManifest([]),
    ) {}
}
