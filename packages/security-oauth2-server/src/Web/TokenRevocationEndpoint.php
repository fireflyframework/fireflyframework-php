<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\ClientAuthenticator;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST {token_revocation_endpoint} (RFC 7009, Spring's OAuth2TokenRevocationEndpointFilter): a confidential
 * client revokes a token it was issued. A refresh token takes the access token down with it (§2.1: SHOULD);
 * an access token goes alone. An unknown token, or a code or id token, is `200` with nothing to say (§2.2: the
 * client cannot learn anything from the difference); a token of ANOTHER client is `invalid_client` — Spring's
 * choice, and the one that keeps a client from probing other clients' tokens.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class TokenRevocationEndpoint implements OAuth2Endpoint
{
    public function __construct(
        private readonly ClientAuthenticator $clients,
        private readonly OAuth2AuthorizationService $authorizations,
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
        try {
            $client = $this->clients->authenticate($request, false);
        } catch (OAuth2AuthenticationException $e) {
            return OAuth2ErrorResponse::fromException($e, ClientAuthenticator::challengeHeaders($request));
        }

        $value = $request->input('token');
        if (! is_string($value) || $value === '') {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'token is required.'));
        }

        $hint = $request->input('token_type_hint');
        $found = TokenIntrospectionEndpoint::locate($this->authorizations, $value, OAuth2TokenType::fromHint(is_string($hint) ? $hint : null));
        if ($found === null) {
            return new HttpResponse('', 200, ['Cache-Control' => 'no-store']);
        }
        [$authorization, $token] = $found;

        if ($authorization->registeredClientId !== $client->client->id) {
            throw new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT, 'The token was not issued to this client.'), 401);
        }

        if ($token->type === OAuth2TokenType::RefreshToken) {
            $authorization = $authorization->withInvalidatedToken(OAuth2TokenType::RefreshToken)->withInvalidatedToken(OAuth2TokenType::AccessToken);
        } elseif ($token->type === OAuth2TokenType::AccessToken) {
            $authorization = $authorization->withInvalidatedToken(OAuth2TokenType::AccessToken);
        } else {
            return new HttpResponse('', 200, ['Cache-Control' => 'no-store']);
        }

        $this->authorizations->save($authorization);
        $this->logger?->info("OAuth2 {$token->type->value} revoked by client [{$client->client->clientId}] for [{$authorization->principalName}].");

        return new HttpResponse('', 200, ['Cache-Control' => 'no-store']);
    }
}
