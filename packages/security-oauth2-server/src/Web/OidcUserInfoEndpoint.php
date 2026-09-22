<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Oidc\OidcUserInfoContext;
use Firefly\Security\OAuth2\Server\Oidc\OidcUserInfoMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET|POST {oidc_user_info_endpoint} (OpenID Connect Core §5.3, Spring's OidcUserInfoEndpointFilter): a bearer
 * access token with the `openid` scope answers the claims the OidcUserInfoMapper builds for its scopes. No
 * bearer is 401 with the bare Bearer challenge (RFC 6750 §3.1: no error code when nothing was presented); a
 * bearer that does not verify or is no longer active is `invalid_token`; one without `openid` is
 * `insufficient_scope` (403) naming the scope. The endpoint verifies the token ITSELF, so it works whether or
 * not the resource-server filter is on — and when that filter is on in the same application it examines the
 * bearer first and refuses an invalid one before this endpoint sees it.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class OidcUserInfoEndpoint implements OAuth2Endpoint
{
    public function __construct(
        private readonly JwtGenerator $jwt,
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly OidcUserInfoMapper $mapper,
    ) {}

    public function methods(): array
    {
        return ['GET', 'POST'];
    }

    public function answersJson(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        $value = BearerToken::value($request);
        if ($value === null) {
            return BearerToken::challenge('A bearer access token is required.', 401, null);
        }

        try {
            [$authorization, $token] = BearerToken::resolve($value, $this->jwt, $this->authorizations);
        } catch (OAuth2AuthenticationException $e) {
            return BearerToken::challenge($e->error()->description, $e->status());
        }

        /** @var list<string> $scopes */
        $scopes = is_array($token->metadata['scopes'] ?? null) ? $token->metadata['scopes'] : $authorization->authorizedScopes;
        if (! in_array('openid', $scopes, true)) {
            return BearerToken::challenge('The access token has no openid scope.', 403, OAuth2ErrorCodes::INSUFFICIENT_SCOPE, 'openid');
        }

        /** @var array<string,mixed> $claims */
        $claims = is_array($token->metadata['claims'] ?? null) ? $token->metadata['claims'] : [];

        return new JsonResponse($this->mapper->map(new OidcUserInfoContext($authorization, $token, $scopes, $claims)), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
