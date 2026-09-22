<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Fixtures\OwnClientStore;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;

/**
 * Binds the application's own client store as THE RegisteredClientRepository of a boot that scans this
 * directory, which is how an application takes over the extension point: a competing bean DEFINITION, not a
 * pre-boot $app->instance(), because OAuth2ServerAutoConfiguration's #[ConditionalOnMissingBean] is evaluated
 * against the BeanDefinitionRegistry (see firefly/data's Fixtures/Capstone/CapstoneTransactionalConfiguration
 * for the full account). #[Order] is the default 0, below the auto-configuration's, so this store is already in
 * the registry when the default asks whether one is missing.
 */
#[Configuration]
final class OwnClientStoreConfiguration
{
    #[Bean]
    public function registeredClientRepository(): RegisteredClientRepository
    {
        return new OwnRegisteredClientRepository;
    }
}
