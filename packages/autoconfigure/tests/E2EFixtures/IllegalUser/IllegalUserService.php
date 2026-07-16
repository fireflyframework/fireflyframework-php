<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Tests\E2EFixtures\IllegalUser;

use Firefly\AutoConfigure\Tests\E2EFixtures\Contracts\SomePort;
use Firefly\Container\Attributes\Service;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;

#[Service]
#[ConditionalOnMissingBean(SomePort::class)] // ILLEGAL on a user component — ConditionPassOne must throw
final class IllegalUserService {}
