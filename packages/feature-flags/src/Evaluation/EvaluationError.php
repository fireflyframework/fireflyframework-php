<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use OpenFeature\interfaces\provider\ErrorCode;

/** The error codes the contract's evaluation table names; the value is the OpenFeature error code. */
enum EvaluationError: string
{
    case FlagNotFound = 'FLAG_NOT_FOUND';
    case ParseError = 'PARSE_ERROR';
    case TypeMismatch = 'TYPE_MISMATCH';
    case General = 'GENERAL';

    public function toOpenFeature(): ErrorCode
    {
        return match ($this) {
            self::FlagNotFound => ErrorCode::FLAG_NOT_FOUND(),
            self::ParseError => ErrorCode::PARSE_ERROR(),
            self::TypeMismatch => ErrorCode::TYPE_MISMATCH(),
            self::General => ErrorCode::GENERAL(),
        };
    }
}
