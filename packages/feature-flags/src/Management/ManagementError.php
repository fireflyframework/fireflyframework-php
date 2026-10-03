<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

/**
 * The §4.8 error codes and LaraFly's status for each. The code is the portable signal (PyFly's actuator answers
 * 400 for every one of them); the status is what an HTTP client of THIS framework branches on.
 */
enum ManagementError: string
{
    case WritesDisabled = 'writes-disabled';
    case NotWritable = 'not-writable';
    case InvalidDefinition = 'invalid-definition';
    case UnknownFlag = 'unknown-flag';
    case UnknownVariant = 'unknown-variant';
    case Conflict = 'conflict';
    case BadRequest = 'bad-request';

    public function status(): int
    {
        return match ($this) {
            self::WritesDisabled => 403,
            self::NotWritable, self::Conflict => 409,
            self::InvalidDefinition, self::UnknownVariant => 422,
            self::UnknownFlag => 404,
            self::BadRequest => 400,
        };
    }
}
