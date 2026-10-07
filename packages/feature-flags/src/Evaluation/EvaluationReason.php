<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

/** The resolution reasons the contract's evaluation table names; the value is the OpenFeature reason string. */
enum EvaluationReason: string
{
    case Static = 'STATIC';
    case Default = 'DEFAULT';
    case TargetingMatch = 'TARGETING_MATCH';
    case Disabled = 'DISABLED';
    case Error = 'ERROR';
}
