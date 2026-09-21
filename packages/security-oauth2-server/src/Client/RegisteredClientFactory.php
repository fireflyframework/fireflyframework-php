<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;

/**
 * Builds a RegisteredClient from a `clients.{id}` block and REFUSES, naming the client, everything that would
 * only fail on a live request: an unknown method or grant, a confidential method with no secret, a secret the
 * DelegatingPasswordEncoder could never match (no `{id}` prefix — it answers false, silently, for every attempt),
 * `none` beside a secret, an authorization_code client with no redirect URI, a redirect URI that is not absolute
 * or carries a fragment (RFC 6749 §3.1.2), a private_key_jwt client with no JWK set. Spring's defaults: the map
 * key is the client id and the name, `client_secret_basic`, `authorization_code` + `refresh_token`.
 *
 * The rules that span more than one field live in assertConsistent(), which every store runs against the client
 * it is about to hand out — this factory after building from a config block, the `eloquent` driver after mapping
 * a row — so a client is held to ONE rule set whichever way it arrived, as Spring's RegisteredClient.Builder
 * validates on every build, a JDBC read included.
 */
final class RegisteredClientFactory
{
    /**
     * @param  array<string,mixed>  $block
     */
    public static function fromConfig(string $key, array $block, AuthorizationServerSettings $settings): RegisteredClient
    {
        $clientId = is_string($block['client_id'] ?? null) && $block['client_id'] !== '' ? $block['client_id'] : $key;
        $secret = $block['client_secret'] ?? null;

        return self::assertConsistent(new RegisteredClient(
            id: $key,
            clientId: $clientId,
            clientIdIssuedAt: null,
            clientSecret: is_string($secret) && $secret !== '' ? $secret : null,
            clientSecretExpiresAt: null,
            clientName: is_string($block['client_name'] ?? null) && $block['client_name'] !== '' ? $block['client_name'] : $clientId,
            clientAuthenticationMethods: self::methods($block['client_authentication_methods'] ?? ['client_secret_basic'], $key),
            authorizationGrantTypes: self::grants($block['authorization_grant_types'] ?? ['authorization_code', 'refresh_token'], $key),
            redirectUris: self::strings($block['redirect_uris'] ?? [], 'redirect_uris', $key),
            postLogoutRedirectUris: self::strings($block['post_logout_redirect_uris'] ?? [], 'post_logout_redirect_uris', $key),
            scopes: self::strings($block['scopes'] ?? [], 'scopes', $key),
            clientSettings: ClientSettings::fromArray(self::map($block['client_settings'] ?? [], 'client_settings', $key), $settings->consentRequired, $key),
            tokenSettings: TokenSettings::fromArray(self::map($block['token_settings'] ?? [], 'token_settings', $key), $settings, $key),
        ));
    }

    /**
     * The rules that hold BETWEEN a client's fields, checked on the built object so a config block and a database
     * row meet exactly the same ones: a confidential method (client_secret_basic/client_secret_post) needs a
     * secret; a secret carries the `{id}` prefix the DelegatingPasswordEncoder dispatches on (a plain one answers
     * false, silently, for every attempt); `none` is public and carries no secret; the authorization_code grant
     * needs at least one redirect URI; every redirect and post-logout URI is absolute and fragment-free (RFC 6749
     * §3.1.2); private_key_jwt needs the client's JWK set. Each refusal names the client by its id. Returns the
     * client so a builder can hand it straight out.
     */
    public static function assertConsistent(RegisteredClient $client): RegisteredClient
    {
        $id = $client->id;
        $methods = $client->clientAuthenticationMethods;
        $secret = $client->clientSecret;

        $confidential = array_filter($methods, static fn (ClientAuthenticationMethod $m): bool => $m->isConfidential() && $m !== ClientAuthenticationMethod::PrivateKeyJwt);
        if ($confidential !== [] && $secret === null) {
            throw new ConfigurationException("Client [{$id}]: client_secret is required for client_secret_basic/client_secret_post (the ENCODED secret, e.g. {bcrypt}…).");
        }
        if ($secret !== null && ! preg_match('/^\{[a-z0-9]+\}/', $secret)) {
            throw new ConfigurationException("Client [{$id}]: client_secret must be an encoded value with an {id} prefix ({bcrypt}…, {argon2id}…, or {noop}… in development); a plain secret would never match.");
        }
        if (in_array(ClientAuthenticationMethod::None, $methods, true) && $secret !== null) {
            throw new ConfigurationException("Client [{$id}]: a client that authenticates with `none` is public and must not carry a client_secret.");
        }
        if (in_array(AuthorizationGrantType::AuthorizationCode, $client->authorizationGrantTypes, true) && $client->redirectUris === []) {
            throw new ConfigurationException("Client [{$id}]: redirect_uris is required for the authorization_code grant.");
        }
        foreach ($client->redirectUris as $uri) {
            self::assertRedirectUri($uri, $id);
        }
        foreach ($client->postLogoutRedirectUris as $uri) {
            self::assertRedirectUri($uri, $id);
        }
        if (in_array(ClientAuthenticationMethod::PrivateKeyJwt, $methods, true) && $client->clientSettings->jwkSet === null) {
            throw new ConfigurationException("Client [{$id}]: private_key_jwt needs client_settings.jwk_set (the client's public JWKS).");
        }

        return $client;
    }

    /** RFC 6749 §3.1.2: absolute, and no fragment. */
    public static function assertRedirectUri(string $uri, string $client): void
    {
        $parts = parse_url($uri);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException("Client [{$client}]: redirect URI [{$uri}] must be absolute.");
        }
        if (isset($parts['fragment']) || str_contains($uri, '#')) {
            throw new ConfigurationException("Client [{$client}]: redirect URI [{$uri}] must not carry a fragment.");
        }
    }

    /** An opaque id for a dynamically registered client: 128 random bits, hex. */
    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return list<ClientAuthenticationMethod>
     */
    public static function methods(mixed $values, string $client): array
    {
        $methods = [];
        foreach (self::strings($values, 'client_authentication_methods', $client) as $value) {
            $methods[] = ClientAuthenticationMethod::tryFrom($value)
                ?? throw new ConfigurationException("Client [{$client}]: client_authentication_methods holds an unknown method [{$value}]; use client_secret_basic, client_secret_post, private_key_jwt or none.");
        }
        if ($methods === []) {
            throw new ConfigurationException("Client [{$client}]: client_authentication_methods must name at least one method.");
        }

        return $methods;
    }

    /**
     * @return list<AuthorizationGrantType>
     */
    public static function grants(mixed $values, string $client): array
    {
        $grants = [];
        foreach (self::strings($values, 'authorization_grant_types', $client) as $value) {
            $grants[] = AuthorizationGrantType::tryFrom($value)
                ?? throw new ConfigurationException("Client [{$client}]: authorization_grant_types holds an unknown grant [{$value}]; use authorization_code, client_credentials or refresh_token.");
        }
        if ($grants === []) {
            throw new ConfigurationException("Client [{$client}]: authorization_grant_types must name at least one grant.");
        }

        return $grants;
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value, string $what, string $client): array
    {
        if (! is_array($value)) {
            throw new ConfigurationException("Client [{$client}]: {$what} must be a list of strings.");
        }
        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new ConfigurationException("Client [{$client}]: {$what} must be a list of non-empty strings.");
            }
            $strings[] = $item;
        }

        return array_values(array_unique($strings));
    }

    /**
     * @return array<string,mixed>
     */
    private static function map(mixed $value, string $what, string $client): array
    {
        if (! is_array($value)) {
            throw new ConfigurationException("Client [{$client}]: {$what} must be a map.");
        }

        /** @var array<string,mixed> $value */
        return $value;
    }
}
