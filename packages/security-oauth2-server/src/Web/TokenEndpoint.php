<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticator;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;
use Firefly\Security\OAuth2\Server\Token\TokenEndpointRateLimiter;
use Firefly\Security\OAuth2\Server\Web\Grant\TokenGrants;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST {token_endpoint} (RFC 6749 §3.2, Spring's OAuth2TokenEndpointFilter), in this order: the rate limiter
 * (keyed by the client id the request presents, else the IP — BEFORE authentication, so a secret cannot be
 * brute-forced at full speed; a refusal is `429 temporarily_unavailable` with `Retry-After`), client
 * authentication (public clients allowed; a failure is `invalid_client` 401 with the Basic challenge when Basic
 * was tried), `grant_type` (missing → `invalid_request`, unknown → `unsupported_grant_type`, one the client did
 * not register → `unauthorized_client`), then the grant, whose authorization is saved and whose response is
 * answered with `Cache-Control: no-store`. The CLIENT is what this endpoint authenticates: a browser session
 * on the same request means nothing here.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class TokenEndpoint implements OAuth2Endpoint
{
    public function __construct(
        private readonly ClientAuthenticator $clients,
        private readonly TokenGrants $grants,
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly ?TokenEndpointRateLimiter $rateLimiter = null,
        private readonly ?LoggerInterface $logger = null,
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
        if ($this->rateLimiter !== null && ! $this->rateLimiter->acquire(self::rateLimitKey($request))) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::TEMPORARILY_UNAVAILABLE, 'Too many token requests; try again later.'), 429, ['Retry-After' => '1']);
        }

        try {
            $client = $this->clients->authenticate($request, true);

            $grantType = $request->input('grant_type');
            if (! is_string($grantType) || $grantType === '') {
                throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'grant_type is required.'));
            }
            $grant = $this->grants->for($grantType);
            if ($grant === null) {
                throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::UNSUPPORTED_GRANT_TYPE, "The grant type [{$grantType}] is not supported."));
            }
            if (! $client->client->supportsGrant($grant->grantType())) {
                throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::UNAUTHORIZED_CLIENT, "Client [{$client->client->clientId}] is not registered for the {$grantType} grant."));
            }

            $issuance = $grant->grant($client, $request);
            $this->authorizations->save($issuance->authorization);
            $this->logger?->info("OAuth2 token issued to client [{$client->client->clientId}] through {$grantType} for [{$issuance->authorization->principalName}].");

            return $issuance->response->toResponse();
        } catch (OAuth2AuthenticationException $e) {
            return OAuth2ErrorResponse::fromException($e, ClientAuthenticator::challengeHeaders($request));
        }
    }

    /**
     * The `scope` parameter split on whitespace, or null when the request carries none.
     *
     * @return list<string>|null
     */
    public static function scopes(Request $request): ?array
    {
        $scope = $request->input('scope');
        if (! is_string($scope) || trim($scope) === '') {
            return null;
        }

        return array_values(array_unique(preg_split('/\s+/', trim($scope)) ?: []));
    }

    private static function rateLimitKey(Request $request): string
    {
        $presented = ClientAuthenticator::presented($request);
        $clientId = $presented[0][1] ?? '';

        return $clientId !== '' ? 'client:'.$clientId : 'ip:'.(string) $request->ip();
    }
}
