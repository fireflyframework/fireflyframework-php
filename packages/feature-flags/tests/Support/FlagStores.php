<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\FeatureFlags\Store\FlagStore;
use Firefly\FeatureFlags\Store\MemoryFlagStore;

final class FlagStores
{
    /** @return Closure(): DateTimeImmutable */
    public static function clock(): Closure
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC'));
    }

    /** @return array<string, array{Closure(): FlagStore}> */
    public static function drivers(): array
    {
        return ['memory' => [static fn (): FlagStore => new MemoryFlagStore(self::clock())]];
    }
}
