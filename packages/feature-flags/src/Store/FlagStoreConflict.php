<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use RuntimeException;

final class FlagStoreConflict extends RuntimeException
{
    public function __construct(public readonly string $flagKey, public readonly ?int $expectedVersion, public readonly ?int $actualVersion)
    {
        parent::__construct(sprintf(
            'Flag [%s] is at version %s, the write expected %s.',
            $flagKey,
            $actualVersion === null ? 'none (no stored row)' : (string) $actualVersion,
            $expectedVersion === null ? 'any version' : (string) $expectedVersion,
        ));
    }

    public static function check(string $key, ?int $expected, ?int $actual): void
    {
        if ($expected !== null && (($expected === 0 && $actual !== null) || ($expected !== 0 && $actual !== $expected))) {
            throw new self($key, $expected, $actual);
        }
    }
}
