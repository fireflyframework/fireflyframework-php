<?php

declare(strict_types=1);

namespace Firefly\Context\Condition;

/**
 * Base marker for a condition that can be evaluated WITHOUT knowing about other beans
 * (config, class presence, active profiles). Evaluated in the ConditionEvaluator's pass 1.
 *
 * A marker interface rather than an enum/match: phase membership becomes a TYPE FACT, so the
 * evaluator can partition conditions with instanceof and cannot forget a case.
 */
interface ConditionAttribute {}
