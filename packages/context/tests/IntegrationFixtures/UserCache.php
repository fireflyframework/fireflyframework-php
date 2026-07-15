<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;

/**
 * The user-supplied CachePort implementation. Its mere presence must make
 * DefaultCacheAutoConfig's #[ConditionalOnMissingBean(CachePort::class)] back off.
 */
#[Component]
final class UserCache implements CachePort {}
