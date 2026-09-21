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
     * The `registration.{id}` defaults the preset contributes (a configured key always wins).
     *
     * @return array<string, mixed>
     */
    public function registration(): array
    {
        return match ($this) {
            self::Google => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Google', 'client_authentication_method' => 'client_secret_basic'],
            self::GitHub => ['scope' => ['read:user'], 'client_name' => 'GitHub', 'client_authentication_method' => 'client_secret_basic'],
            self::Okta => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Okta', 'client_authentication_method' => 'client_secret_basic'],
            self::Keycloak => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Keycloak', 'client_authentication_method' => 'client_secret_basic'],
            self::Microsoft => ['scope' => ['openid', 'profile', 'email'], 'client_name' => 'Microsoft', 'client_authentication_method' => 'client_secret_basic'],
        };
    }
}
