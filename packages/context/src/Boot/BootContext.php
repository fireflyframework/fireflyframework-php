<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Illuminate\Container\Container;

/**
 * State threaded through the boot passes.
 *
 * Deliberately NOT bound as a container singleton: boot-time state must not be reachable from
 * request state (it would survive across Octane requests).
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
    ) {}
}
