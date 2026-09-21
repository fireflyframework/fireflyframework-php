<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientProperties;
use Firefly\Security\OAuth2\Client\Registration\OAuth2ClientPropertiesMapper;
use Firefly\Security\OAuth2\Client\Registration\PropertiesClientRegistrationRepository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;

/**
 * The opt-in bean source of the OAuth2 client. `firefly.security.oauth2.client.enabled` is the package master:
 * it gates the registrations, the discovery, the token client and the authorized-client manager — none of which
 * needs `firefly.security.enabled`, because a job that calls an API with client credentials has no inbound
 * security to speak of. The login half (`login.enabled`) additionally requires the security master flag, since
 * it signs into the master-gated session repository; OAuth2ClientWiringPass refuses the combination that lacks
 * it. #[Order(600)] is above SecurityAutoConfiguration's 500 so the security beans register first; every bean
 * here is #[ConditionalOnMissingBean] so an application's own binding always wins.
 */
#[Configuration]
#[Order(600)]
final class OAuth2ClientAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2ClientSettings::class)]
    public function oauth2ClientSettings(Config $config): OAuth2ClientSettings
    {
        return OAuth2ClientSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OidcDiscovery::class)]
    public function oidcDiscovery(Container $container, Cache $cache, OAuth2ClientSettings $settings): OidcDiscovery
    {
        return new OidcDiscovery($container, $cache, $settings);
    }

    /**
     * The registrations, from `registration.{id}`/`provider.{id}`. The mapper's static validation runs HERE,
     * at construction — and the EagerSingletonsPass constructs every bean at boot — so a registration that could
     * never work is a startup failure naming its key, never a 500 on the first login; OAuth2ClientWiringPass
     * resolves the bean explicitly as well, the belt SecurityWiringPass wears for the user store.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(ClientRegistrationRepository::class)]
    public function clientRegistrationRepository(Config $config, OidcDiscovery $discovery, OAuth2ClientSettings $settings): ClientRegistrationRepository
    {
        $mapper = new OAuth2ClientPropertiesMapper(OAuth2ClientProperties::fromConfig($config), $discovery, $settings);
        $mapper->validate();

        return new PropertiesClientRegistrationRepository($mapper);
    }
}
