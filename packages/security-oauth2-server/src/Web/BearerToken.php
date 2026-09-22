<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use DateTimeImmutable;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The server as a resource server for its OWN endpoints (userinfo, registration): the bearer off the
 * Authorization header, resolved to the authorization it belongs to. A JWT is verified against the server's
 * keys AND must still be active in the store (revocation is honoured); a reference token is looked up. Every
 * failure is `invalid_token` (401) with one sentence. `challenge()` builds the RFC 6750 §3 response.
 */
final class BearerToken
{
    public static function value(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $value = trim(substr($header, 7));

        return $value === '' ? null : $value;
    }

    /**
     * @return array{0: OAuth2Authorization, 1: OAuth2Token}
     *
     * @throws OAuth2AuthenticationException
     */
    public static function resolve(string $value, JwtGenerator $jwt, OAuth2AuthorizationService $authorizations): array
    {
        if (substr_count($value, '.') === 2) {
            try {
                $jwt->decode($value);
            } catch (Throwable) {
                throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN, 'The access token could not be verified.'), 401);
            }
        }

        $authorization = $authorizations->findByToken($value, OAuth2TokenType::AccessToken);
        $token = $authorization?->token(OAuth2TokenType::AccessToken);
        if ($authorization === null || $token === null || ! $token->isActive(new DateTimeImmutable)) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN, 'The access token is unknown, revoked or expired.'), 401);
        }

        return [$authorization, $token];
    }

    public static function challenge(string $description, int $status = 401, ?string $error = OAuth2ErrorCodes::INVALID_TOKEN, ?string $scope = null): JsonResponse
    {
        $parts = ['realm="oauth2"'];
        if ($error !== null) {
            $parts[] = 'error="'.$error.'"';
            $parts[] = 'error_description="'.str_replace('"', "'", $description).'"';
        }
        if ($scope !== null) {
            $parts[] = 'scope="'.$scope.'"';
        }

        return new JsonResponse(
            (new OAuth2Error($error ?? OAuth2ErrorCodes::INVALID_TOKEN, $description))->toArray(),
            $status,
            ['WWW-Authenticate' => 'Bearer '.implode(', ', $parts), 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }
}
