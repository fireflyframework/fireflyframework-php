<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\JwksDocumentSource;
use Firefly\Security\OAuth2\Server\Jose\AuthorizationServerJwksDocumentSource;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\JwtSigningKeys;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

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
}
