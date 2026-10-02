<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Context;

/**
 * Adds attributes to the ambient evaluation context (CONTRACT.md "Evaluation context"). Register one as a
 * #[Component]; contributors run in #[Order] order — the built-ins first (application and profiles at -200,
 * Security's principal — targeting key, roles, tenant — at -100) — and a later one may override or remove what an
 * earlier one set. The caller's explicit context is laid over all of them. Collected through Container::getAll(),
 * so a #[Bean] returning a concrete contributor is NOT seen: use #[Component].
 *
 * Set JSON values (scalars, lists, maps) and date-times; a date-time is evaluated as epoch milliseconds. A value
 * OpenFeature cannot carry (any other object) and a numeric-looking name (an int key in PHP) are dropped.
 */
interface EvaluationContextContributor
{
    public function contribute(EvaluationContextBuilder $context): void;
}
