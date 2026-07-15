<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProfile;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

/**
 * A ContextScannerTest fixture proving #[ConditionalOn*] is captured both on the CLASS itself and
 * on individual #[Bean] methods — including a VARIADIC constructor (#[ConditionalOnProfile]) to
 * prove ContextScanner's arg-serialization round-trips positional AND variadic constructor
 * arguments correctly.
 */
#[Configuration]
#[ConditionalOnProperty(name: 'feature.enabled', matchIfMissing: true)]
final class ConditionalConfigFixture
{
    #[Bean]
    #[ConditionalOnClass(class: self::class)]
    public function classGated(): self
    {
        return new self;
    }

    #[Bean]
    #[ConditionalOnProfile('dev', 'test')]
    public function profileGated(): self
    {
        return new self;
    }
}
