<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

use DateTimeImmutable;
use Firefly\Security\Core\Authentication;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Jose\Jwk;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Settings\OAuth2TokenFormat;

/**
 * Mints the tokens of a grant (Spring's DelegatingOAuth2TokenGenerator: JwtGenerator + OAuth2AccessTokenGenerator
 * + OAuth2RefreshTokenGenerator, in one): the access token as a JWT (RFC 9068's claims — `iss`, `sub`, `aud` =
 * [client_id], `exp`, `iat`, `nbf`, `jti`, `scope` space-delimited as OAuth2ResourceServerFilter reads it,
 * `client_id`) or as an opaque reference whose claims live only in the store; a refresh token when the grant
 * allows one; an id token when `openid` was granted (`azp`, `auth_time`, `nonce`, `sid` from the authorization's
 * attributes, `at_hash` over the access token just minted). The customizer runs last on both JWTs. Every token is
 * recorded on the returned authorization with its plain value still attached — the endpoint saves it, and the
 * services strip the values.
 */
final class OAuth2TokenGenerator
{
    public function __construct(
        private readonly JwtGenerator $jwt,
        private readonly AuthorizationServerSettings $settings,
        private readonly ?OAuth2TokenCustomizer $customizer = null,
    ) {}

    public function jwt(): JwtGenerator
    {
        return $this->jwt;
    }

    public function issue(OAuth2Authorization $authorization, RegisteredClient $client, bool $withRefreshToken, ?Authentication $principal = null): TokenIssuance
    {
        $now = new DateTimeImmutable;
        $scopes = $authorization->authorizedScopes;
        $tokenSettings = $client->tokenSettings;

        $accessExpiry = $now->modify("+{$tokenSettings->accessTokenTtl} seconds");
        $accessClaims = [
            'iss' => $this->settings->issuer,
            'sub' => $authorization->principalName,
            'aud' => [$client->clientId],
            'exp' => $accessExpiry->getTimestamp(),
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp(),
            'jti' => bin2hex(random_bytes(16)),
            'scope' => implode(' ', $scopes),
            'client_id' => $client->clientId,
        ];
        $accessClaims = $this->customize(OAuth2TokenType::AccessToken, $client, $authorization, $accessClaims, $principal);

        $accessValue = $tokenSettings->accessTokenFormat === OAuth2TokenFormat::SelfContained ? $this->jwt->encode($accessClaims) : self::opaque();
        $authorization = $authorization->withToken(OAuth2Token::issue(OAuth2TokenType::AccessToken, $accessValue, $now, $accessExpiry, [
            'claims' => $accessClaims,
            'scopes' => $scopes,
            'format' => $tokenSettings->accessTokenFormat->value,
        ]));

        $refreshValue = null;
        if ($withRefreshToken) {
            $refreshValue = self::opaque();
            $authorization = $authorization->withToken(OAuth2Token::issue(OAuth2TokenType::RefreshToken, $refreshValue, $now, $now->modify("+{$tokenSettings->refreshTokenTtl} seconds")));
        }

        $idValue = null;
        if (in_array('openid', $scopes, true)) {
            $idExpiry = $now->modify("+{$tokenSettings->idTokenTtl} seconds");
            $idClaims = [
                'iss' => $this->settings->issuer,
                'sub' => $authorization->principalName,
                'aud' => [$client->clientId],
                'azp' => $client->clientId,
                'exp' => $idExpiry->getTimestamp(),
                'iat' => $now->getTimestamp(),
                'nbf' => $now->getTimestamp(),
                'at_hash' => Jwk::base64url(substr(hash('sha256', $accessValue, true), 0, 16)),
            ];
            foreach (['auth_time', 'nonce', 'sid'] as $attribute) {
                $value = $authorization->attribute($attribute);
                if ($value !== null) {
                    $idClaims[$attribute] = $value;
                }
            }
            $idClaims = $this->customize(OAuth2TokenType::IdToken, $client, $authorization, $idClaims, $principal);
            $idValue = $this->jwt->encode($idClaims);
            $authorization = $authorization->withToken(OAuth2Token::issue(OAuth2TokenType::IdToken, $idValue, $now, $idExpiry, ['claims' => $idClaims]));
        }

        return new TokenIssuance($authorization, new OAuth2AccessTokenResponse($accessValue, $tokenSettings->accessTokenTtl, $scopes, $refreshValue, $idValue));
    }

    /** 32 random bytes, base64url: a code, a refresh token, a reference access token. */
    public static function opaque(): string
    {
        return Jwk::base64url(random_bytes(32));
    }

    /**
     * @param  array<string,mixed>  $claims
     * @return array<string,mixed>
     */
    private function customize(OAuth2TokenType $type, RegisteredClient $client, OAuth2Authorization $authorization, array $claims, ?Authentication $principal): array
    {
        if ($this->customizer === null) {
            return $claims;
        }

        $context = new JwtEncodingContext($type, $client, $authorization->principalName, $authorization->authorizedScopes, $authorization->authorizationGrantType, $claims, $principal);
        $this->customizer->customize($context);

        return $context->claims();
    }
}
