<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/** Health status with a fixed severity ordering (DOWN > OUT_OF_SERVICE > UP > UNKNOWN); DOWN statuses map to 503. */
enum Status: string
{
    case Up = 'UP';
    case Down = 'DOWN';
    case OutOfService = 'OUT_OF_SERVICE';
    case Unknown = 'UNKNOWN';

    public function severity(): int
    {
        return match ($this) {
            self::Down => 3,
            self::OutOfService => 2,
            self::Up => 1,
            self::Unknown => 0,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Down, self::OutOfService => 503,
            default => 200,
        };
    }
}
