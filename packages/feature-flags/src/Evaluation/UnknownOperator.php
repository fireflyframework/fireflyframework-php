<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

/** A rule names an operation nobody registered — an unresolved {"$ref": …} among them: PARSE_ERROR. */
final class UnknownOperator extends JsonLogicError
{
    public function __construct(public readonly string $operator)
    {
        parent::__construct("Unrecognized operation [{$operator}].");
    }
}
