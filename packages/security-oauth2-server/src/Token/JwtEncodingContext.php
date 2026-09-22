<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

use Firefly\Security\Core\Authentication;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;

/**
 * What an OAuth2TokenCustomizer sees (Spring's JwtEncodingContext): which token is being built, for which
 * client, principal, scopes and grant, the signed-in Authentication when there is one (null for client
 * credentials), and the claims so far — mutable through claim()/removeClaim(), so a customizer adds `roles` or a
 * tenant id, or drops what it does not want published.
 */
final class JwtEncodingContext
{
    /**
     * @param  list<string>  $authorizedScopes
     * @param  array<string,mixed>  $claims
     */
    public function __construct(
        public readonly OAuth2TokenType $tokenType,
        public readonly RegisteredClient $registeredClient,
        public readonly string $principalName,
        public readonly array $authorizedScopes,
        public readonly AuthorizationGrantType $authorizationGrantType,
        private array $claims,
        public readonly ?Authentication $principal = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    public function claim(string $name, mixed $value): void
    {
        $this->claims[$name] = $value;
    }

    public function removeClaim(string $name): void
    {
        unset($this->claims[$name]);
    }
}
