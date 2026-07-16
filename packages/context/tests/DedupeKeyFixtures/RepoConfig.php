<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

/**
 * TWO `#[Bean]` factories, each returning a declared INTERFACE (never scanned), each constructing its
 * OWN `Repo` instance — so the container ends up with two distinct singletons of the SAME concrete
 * class, bound under two DIFFERENT abstracts. `RegisterBeanPostProcessorsPass`'s dedupe guard must
 * register the late-bound listener for BOTH abstracts; keying the guard on `$concreteClass` (`Repo`)
 * instead of the abstract makes the second `#[Bean]`'s listener silently never register.
 */
#[Configuration]
final class RepoConfig
{
    #[Bean]
    public function makeRead(DedupeRecorder $recorder): ReadPort
    {
        return new Repo($recorder);
    }

    #[Bean]
    public function makeWrite(DedupeRecorder $recorder): WritePort
    {
        return new Repo($recorder);
    }
}
