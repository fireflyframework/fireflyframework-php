<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\LogoutContext;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Security\Session\SecurityContextRepository;

/**
 * Binds the counting repository as THE SecurityContextRepository of a boot that scans this directory, the way
 * an application binds its own store of the context between requests (the port's docblock offers "a signed
 * cookie, a cache keyed by a device id").
 *
 * A competing bean DEFINITION is the override seam, not a pre-boot $app->instance(): SecurityAutoConfiguration's
 * #[ConditionalOnMissingBean(SecurityContextRepository::class)] default is evaluated against the
 * BeanDefinitionRegistry, so a container binding is invisible to the condition AND is overwritten when the
 * definitions are flushed (see firefly/data's Fixtures/Capstone/CapstoneTransactionalConfiguration for the full
 * account). This configuration's #[Order] is the default 0, below the auto-configuration's, so it is already in
 * the registry when the default asks whether one is missing.
 */
#[Configuration]
final class LogoutContextConfiguration
{
    #[Bean]
    public function securityContextRepository(): SecurityContextRepository
    {
        return CountingSecurityContextRepository::shared();
    }
}
