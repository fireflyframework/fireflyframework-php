<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

/** The JSON Logic engine with flagd's four operators registered, as the reference evaluator's OPERATORS table. */
final class FlagdOperators
{
    public static function jsonLogic(): JsonLogic
    {
        return new JsonLogic([
            'fractional' => Fractional::evaluate(...),
            'sem_ver' => SemVer::evaluate(...),
            'starts_with' => StringOps::startsWith(...),
            'ends_with' => StringOps::endsWith(...),
        ]);
    }
}
