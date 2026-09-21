<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/**
 * The presets (Spring's CommonOAuth2Provider, extended with the per-tenant providers the brief names): a
 * `provider:` value that names one of these — or a registration whose id IS one of these — starts from the
 * preset's provider block and registration defaults, and the configured `provider.{id}` keys overlay it.
 *
 * Google and GitHub are fully spelled out (their endpoints are global). Okta, Keycloak and Microsoft Entra are
 * per-tenant: there is no global authorization endpoint, so the preset contributes the scopes, the name and the
 * name attribute, and REQUIRES `provider.{id}.issuer_uri` — the tenant's issuer — from which discovery learns
 * the rest. `entra` is accepted as an alias of `microsoft`.
 *
 * THE CLIENT AUTHENTICATION METHOD IS A PRESET DEFAULT ONLY WHERE THE PROVIDER LEAVES NO CHOICE. A Google or
 * GitHub OAuth app that signs a person into a server-side application is a confidential client — neither
 * provider issues a web client without a secret — so those two presets say `client_secret_basic` outright, and
 * OAuth2ClientPropertiesMapper refuses at boot a registration that names them without a `client_secret`
 * (rather than sending an empty one). Okta, Keycloak and Entra host public clients as a matter of course (a
 * PKCE-only client is one toggle in each console), so the per-tenant presets leave the method to the
 * registration: `client_authentication_method` when set, else `client_secret_basic` when a secret is
 * configured and `none` — a public client, PKCE mandatory — when none is.
 */
enum CommonOAuth2Provider: string
{
    case Google = 'google';
    case GitHub = 'github';
    case Okta = 'okta';
    case Keycloak = 'keycloak';
    case Microsoft = 'microsoft';

    public static function tryFromId(string $id): ?self
    {
        $id = strtolower($id);

        return $id === 'entra' ? self::Microsoft : self::tryFrom($id);
    }

    /** Whether the preset can only work with a configured `issuer_uri` (a per-tenant provider). */
    public function requiresIssuer(): bool
    {
        return match ($this) {
            self::Google, self::GitHub => false,
            self::Okta, self::Keycloak, self::Microsoft => true,
        };
    }

    /**
     * The `provider.{id}` block the preset contributes.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return match ($this) {
            self::Google => [
                'issuer_uri' => 'https://accounts.google.com',
                'authorization_uri' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_uri' => 'https://www.googleapis.com/oauth2/v4/token',
                'jwk_set_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
                'user_info_uri' => 'https://www.googleapis.com/oauth2/v3/userinfo',
                'user_name_attribute' => 'sub',
            ],
            self::GitHub => [
                'authorization_uri' => 'https://github.com/login/oauth/authorize',
                'token_uri' => 'https://github.com/login/oauth/access_token',
                'user_info_uri' => 'https://api.github.com/user',
                'user_name_attribute' => 'id',
            ],
            self::Okta, self::Keycloak, self::Microsoft => [
                'user_name_attribute' => 'sub',
            ],
        };
    }

    /**
     * The `registration.{id}` defaults the preset contributes (a configured key always wins). Only the two
     * confidential-only providers name a `client_authentication_method`; see the class docblock.
     *
     * @return array<string, mixed>
     */
    public function registration(): array
    {
        return match ($this) {
            self::Google => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Google', 'client_authentication_method' => 'client_secret_basic'],
            self::GitHub => ['scope' => ['read:user'], 'client_name' => 'GitHub', 'client_authentication_method' => 'client_secret_basic'],
            self::Okta => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Okta'],
            self::Keycloak => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Keycloak'],
            self::Microsoft => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Microsoft'],
        };
    }
}
