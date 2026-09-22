<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Authorization\TokenHash;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticator;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST {token_introspection_endpoint} (RFC 7662, Spring's OAuth2TokenIntrospectionEndpointFilter): a
 * confidential client asks whether a token is alive. `token_type_hint` is a hint — the named column first,
 * every column when it misses. Anything unknown, revoked, expired, or of a kind that is not introspected (a
 * code, an id token) is `{"active": false}` and nothing else, so the endpoint never says WHY. An active token
 * answers the RFC members: `client_id`, `token_type` (`Bearer` for an access token, `refresh_token` for a
 * refresh token), `scope`, `sub`/`username`, `iss`, `aud`, `iat`, `exp`, `nbf`, `jti` — from the claims the
 * server recorded at issuance, so a reference token and a JWT answer alike. Any authenticated client may
 * introspect any token: resource servers are clients too (Spring's rule).
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class TokenIntrospectionEndpoint implements OAuth2Endpoint
{
    public function __construct(
        private readonly ClientAuthenticator $clients,
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly RegisteredClientRepository $registeredClients,
    ) {}

    public function methods(): array
    {
        return ['POST'];
    }

    public function answersJson(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        try {
            $this->clients->authenticate($request, false);
        } catch (OAuth2AuthenticationException $e) {
            return OAuth2ErrorResponse::fromException($e, ClientAuthenticator::challengeHeaders($request));
        }

        $value = $request->input('token');
        if (! is_string($value) || $value === '') {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'token is required.'));
        }

        $found = self::locate($this->authorizations, $value, OAuth2TokenType::fromHint(self::string($request, 'token_type_hint')));
        if ($found === null) {
            return self::inactive();
        }
        [$authorization, $token] = $found;

        if (! $token->isActive(new DateTimeImmutable) || ! in_array($token->type, [OAuth2TokenType::AccessToken, OAuth2TokenType::RefreshToken], true)) {
            return self::inactive();
        }

        $client = $this->registeredClients->findById($authorization->registeredClientId);
        /** @var array<string,mixed> $claims */
        $claims = is_array($token->metadata['claims'] ?? null) ? $token->metadata['claims'] : [];
        /** @var list<string> $scopes */
        $scopes = is_array($token->metadata['scopes'] ?? null) ? $token->metadata['scopes'] : $authorization->authorizedScopes;

        $document = [
            'active' => true,
            'client_id' => $client->clientId ?? $authorization->registeredClientId,
            'token_type' => $token->type === OAuth2TokenType::AccessToken ? 'Bearer' : 'refresh_token',
            'scope' => implode(' ', $scopes),
            'sub' => $authorization->principalName,
            'username' => $authorization->principalName,
            'iat' => $token->issuedAt->getTimestamp(),
            'exp' => $token->expiresAt?->getTimestamp(),
        ];
        foreach (['iss', 'aud', 'nbf', 'jti'] as $claim) {
            if (array_key_exists($claim, $claims)) {
                $document[$claim] = $claims[$claim];
            }
        }

        return new JsonResponse(array_filter($document, static fn (mixed $v): bool => $v !== null), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * The authorization holding the token and WHICH token it is: the hinted type first, then every type, then the
     * refresh-token family (a superseded refresh token is still the refresh token of that authorization).
     *
     * @return array{0: OAuth2Authorization, 1: OAuth2Token}|null
     */
    public static function locate(OAuth2AuthorizationService $authorizations, string $value, ?OAuth2TokenType $hint): ?array
    {
        $authorization = ($hint === null ? null : $authorizations->findByToken($value, $hint)) ?? $authorizations->findByToken($value);
        if ($authorization === null) {
            return null;
        }

        $hash = TokenHash::of($value);
        foreach ($authorization->tokens as $token) {
            if (hash_equals($token->hash, $hash)) {
                return [$authorization, $token];
            }
        }
        if (in_array($hash, $authorization->refreshTokenFamily(), true)) {
            $refresh = $authorization->token(OAuth2TokenType::RefreshToken);

            return $refresh === null ? null : [$authorization, $refresh->invalidated()];
        }

        return null;
    }

    private static function inactive(): JsonResponse
    {
        return new JsonResponse(['active' => false], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private static function string(Request $request, string $name): ?string
    {
        $value = $request->input($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
