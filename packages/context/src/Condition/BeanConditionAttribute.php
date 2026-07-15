<?php

declare(strict_types=1);

namespace Firefly\Context\Condition;

/**
 * Marker for a condition that DEPENDS ON the bean registry (e.g. "is there a bean of this type
 * already defined?"). Evaluated in the ConditionEvaluator's pass 2, against the
 * BeanDefinitionRegistry — never against resolved instances (matches Spring).
 */
interface BeanConditionAttribute extends ConditionAttribute {}
