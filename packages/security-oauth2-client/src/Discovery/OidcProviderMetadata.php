<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Discovery;

/**
 * The members of a discovery document this package reads (OpenID Connect Discovery 1.0 §3; RFC 8414 for a plain
 * OAuth2 server): the issuer, the two endpoints every flow needs, and the three that are optional. The whole
 * document is kept so an application can read a member the framework does not (`revocation_endpoint`,
 * `scopes_supported`).
 *
 * THE ISSUER IS CHECKED, per Discovery §4.3: a document served at {issuer}/.well-known/openid-configuration
 * whose `issuer` is not that issuer is refused, because everything downstream — the `iss` check on every id
 * token — trusts that value.
 */
final readonly class OidcProviderMetadata
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public ?string $jwksUri,
        public ?string $userInfoEndpoint,
        public ?string $endSessionEndpoint,
        public array $document,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public static function fromDocument(array $document, string $expectedIssuer): self
    {
        $issuer = self::member($document, 'issuer');
        if ($issuer === null) {
            throw ProviderDiscoveryException::invalid($expectedIssuer, 'it names no issuer.');
        }
        if (rtrim($issuer, '/') !== rtrim($expectedIssuer, '/')) {
            throw ProviderDiscoveryException::invalid($expectedIssuer, 'its issuer differs from the configured issuer_uri (OpenID Connect Discovery §4.3).');
        }

        return new self(
            $issuer,
            self::member($document, 'authorization_endpoint') ?? throw ProviderDiscoveryException::invalid($expectedIssuer, 'it names no authorization_endpoint.'),
            self::member($document, 'token_endpoint') ?? throw ProviderDiscoveryException::invalid($expectedIssuer, 'it names no token_endpoint.'),
            self::member($document, 'jwks_uri'),
            self::member($document, 'userinfo_endpoint'),
            self::member($document, 'end_session_endpoint'),
            $document,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function member(array $document, string $name): ?string
    {
        $value = $document[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
