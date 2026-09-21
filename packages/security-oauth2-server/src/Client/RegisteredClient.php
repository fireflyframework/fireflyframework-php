<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

use DateTimeImmutable;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use JsonSerializable;

/**
 * A client that may ask this server for tokens (Spring's RegisteredClient): its credentials, the methods it may
 * authenticate with, the grants it may use, the exact redirect URIs it may be sent back to, the scopes it may
 * ask for, and its own settings. Immutable; the secret is the ENCODED form (`{bcrypt}…`) the PasswordEncoder
 * verifies — and even encoded it stays out of the places a client object tends to end up by accident. Two paths
 * mask it: __debugInfo() covers print_r, var_dump and Symfony's VarDumper, and jsonSerialize() covers
 * json_encode() and a Monolog log context (`Log::info('…', ['client' => $client])` — Monolog's normalizer
 * json-encodes a plain object's public properties and never consults __debugInfo, so the interface is the only
 * thing that keeps the secret out of the application log). Only an explicit `(array)` cast or a direct read of
 * `$client->clientSecret` reaches the value.
 */
final readonly class RegisteredClient implements JsonSerializable
{
    /**
     * @param  list<ClientAuthenticationMethod>  $clientAuthenticationMethods
     * @param  list<AuthorizationGrantType>  $authorizationGrantTypes
     * @param  list<string>  $redirectUris
     * @param  list<string>  $postLogoutRedirectUris
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public ?DateTimeImmutable $clientIdIssuedAt,
        public ?string $clientSecret,
        public ?DateTimeImmutable $clientSecretExpiresAt,
        public string $clientName,
        public array $clientAuthenticationMethods,
        public array $authorizationGrantTypes,
        public array $redirectUris,
        public array $postLogoutRedirectUris,
        public array $scopes,
        public ClientSettings $clientSettings,
        public TokenSettings $tokenSettings,
    ) {}

    /** A public client authenticates with `none` — no secret, PKCE instead. */
    public function isPublic(): bool
    {
        return in_array(ClientAuthenticationMethod::None, $this->clientAuthenticationMethods, true);
    }

    public function supportsGrant(AuthorizationGrantType $grant): bool
    {
        return in_array($grant, $this->authorizationGrantTypes, true);
    }

    public function supportsAuthenticationMethod(ClientAuthenticationMethod $method): bool
    {
        return in_array($method, $this->clientAuthenticationMethods, true);
    }

    /** Exact string comparison, as RFC 6749 §3.1.2.3 requires: no prefix match, no normalisation. */
    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }

    public function hasPostLogoutRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->postLogoutRedirectUris, true);
    }

    /**
     * @param  list<string>  $scopes
     */
    public function hasScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (! in_array($scope, $this->scopes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The server-wide rule, the client's own switch, or the public-client rule — whichever demands PKCE. A client
     * whose ONLY method is `none` always needs it, whatever the switches say: without a secret and without a
     * proof key nothing protects its code. `require_proof_key_for_public_clients` widens only what a client that
     * lists `none` BESIDE a confidential method may skip.
     */
    public function requiresProofKey(AuthorizationServerSettings $settings): bool
    {
        return $settings->requirePkce
            || $this->clientSettings->requireProofKey
            || ($this->isPublic() && ($settings->requireProofKeyForPublicClients || $this->clientAuthenticationMethods === [ClientAuthenticationMethod::None]));
    }

    public function isSecretExpired(DateTimeImmutable $now): bool
    {
        return $this->clientSecretExpiresAt !== null && $this->clientSecretExpiresAt <= $now;
    }

    /**
     * The masked view Monolog and json_encode() see: the secret is replaced by a fixed placeholder (or null when
     * the client has none), the enums by their wire values.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'clientId' => $this->clientId,
            'clientName' => $this->clientName,
            'clientSecret' => $this->clientSecret === null ? null : '********',
            'clientAuthenticationMethods' => array_map(static fn (ClientAuthenticationMethod $m): string => $m->value, $this->clientAuthenticationMethods),
            'authorizationGrantTypes' => array_map(static fn (AuthorizationGrantType $g): string => $g->value, $this->authorizationGrantTypes),
            'redirectUris' => $this->redirectUris,
            'scopes' => $this->scopes,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
