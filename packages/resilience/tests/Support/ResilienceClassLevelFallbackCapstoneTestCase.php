<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Support;

/**
 * ResilienceMethodCapstoneTestCase over the ClassLevelFallback fixtures: the same uncached boot, the same
 * real proxy and the same five `payments` instance blocks, pointed at the one shape in which the class-level
 * fan-out and a #[Fallback] meet.
 *
 * It is a boot of its own rather than another class in the Method directory because the claim being proved
 * is about what the PLAN does NOT contain — that the recovery is not a planned method — and the cleanest way
 * to say that is over a directory holding nothing else.
 */
abstract class ResilienceClassLevelFallbackCapstoneTestCase extends ResilienceMethodCapstoneTestCase
{
    protected function resilienceFixtureDir(): string
    {
        return 'ClassLevelFallback';
    }
}
