<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Security\OAuth2\JwksDocumentSource;
use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2AuthorizationConsentModelRepository;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2AuthorizationModelRepository;
use Firefly\Security\OAuth2\Server\Eloquent\RegisteredClientModelRepository;
use Firefly\Security\OAuth2\Server\Jose\AuthorizationServerJwksDocumentSource;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Illuminate\Container\Container;

/**
 * The authorization server's bean source. Every bean is AND-gated on the master flag and on
 * `firefly.security.oauth2.server.enabled` — the server consumes master-gated beans (PasswordEncoder, SessionCsrf,
 * FormLoginSettings, the entry point), so a surface flag alone must never register anything (the HttpSecurityFilter
 * rule) — and every bean is #[ConditionalOnMissingBean] so an application override always wins. #[Order(600)] is
 * above SecurityAutoConfiguration's 500: this configuration EXTENDS the core and never needs to register first.
 */
#[Configuration]
#[Order(600)]
final class OAuth2ServerAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(AuthorizationServerSettings::class)]
    public function authorizationServerSettings(Config $config): AuthorizationServerSettings
    {
        return AuthorizationServerSettings::fromConfig($config);
    }

    /**
     * The keys, loaded once: an empty or unloadable `jwt.signing_key` refuses here, and OAuth2ServerWiringPass
     * resolves this bean at boot so the refusal is a startup failure.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(JwtSigningKeys::class)]
    public function jwtSigningKeys(AuthorizationServerSettings $settings): JwtSigningKeys
    {
        return JwtSigningKeys::fromSettings($settings);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(JwtGenerator::class)]
    public function jwtGenerator(JwtSigningKeys $keys): JwtGenerator
    {
        return new JwtGenerator($keys);
    }

    /**
     * THE BRIDGE TO THE RESOURCE-SERVER FILTER: the security core asks the container for a JwksDocumentSource
     * when it builds its JwksProvider, and this bean is what makes `jwks_source: local` work in the same
     * application. An application that binds its own source keeps it (missing-bean), and then publishes that.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(JwksDocumentSource::class)]
    public function oauth2JwksDocumentSource(JwtSigningKeys $keys): JwksDocumentSource
    {
        return new AuthorizationServerJwksDocumentSource($keys);
    }

    /**
     * `clients.driver`: `memory` builds the config map (every block validated here, and OAuth2ServerWiringPass
     * resolves this bean at boot so a refusal is a startup failure); `eloquent` reads oauth2_registered_clients
     * through the framework's own EloquentRepository, so a row is validated by the same factory rules where it
     * is read. The map key is read spelled out in full, never through PREFIX, so tests/ConfigReferenceTest.php
     * can see it.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(RegisteredClientRepository::class)]
    public function registeredClientRepository(AuthorizationServerSettings $settings, Config $config, Container $container): RegisteredClientRepository
    {
        if ($settings->clientsDriver === 'eloquent') {
            return new EloquentRegisteredClientRepository(new RegisteredClientModelRepository(translator: self::translator($container)), $settings);
        }

        /** @var array<string,mixed> $clients */
        $clients = $config->has('firefly.security.oauth2.server.clients') ? $config->array('firefly.security.oauth2.server.clients') : [];

        return InMemoryRegisteredClientRepository::fromConfig($clients, $settings);
    }

    /**
     * `authorizations.driver`: `memory` is a per-process map, right for tests and one dev server; `eloquent` is
     * the oauth2_authorizations table, which a deployment with more than one worker needs, because a code
     * issued by one process must be redeemable by another.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AuthorizationService::class)]
    public function oauth2AuthorizationService(AuthorizationServerSettings $settings, Container $container): OAuth2AuthorizationService
    {
        return $settings->authorizationsDriver === 'eloquent'
            ? new EloquentOAuth2AuthorizationService(new OAuth2AuthorizationModelRepository(translator: self::translator($container)))
            : new InMemoryOAuth2AuthorizationService;
    }

    /** Consents follow `authorizations.driver`: they live beside the authorizations, in memory or in oauth2_authorization_consents. */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(OAuth2AuthorizationConsentService::class)]
    public function oauth2AuthorizationConsentService(AuthorizationServerSettings $settings, Container $container): OAuth2AuthorizationConsentService
    {
        return $settings->authorizationsDriver === 'eloquent'
            ? new EloquentOAuth2AuthorizationConsentService(new OAuth2AuthorizationConsentModelRepository(translator: self::translator($container)))
            : new InMemoryOAuth2AuthorizationConsentService;
    }

    /** firefly/data's translator when its provider is booted (so `exception-translation.enabled` is honoured), the enabled default otherwise. */
    private static function translator(Container $container): ?PersistenceExceptionTranslator
    {
        if (! $container->bound(PersistenceExceptionTranslator::class)) {
            return null;
        }

        /** @var PersistenceExceptionTranslator */
        return $container->make(PersistenceExceptionTranslator::class);
    }
}
