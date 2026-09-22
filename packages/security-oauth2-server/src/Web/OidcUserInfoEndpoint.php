<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
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
 *
 * THE SCOPE IS NOT THE WHOLE TEST: THERE MUST BE AN END USER. OIDC Core §5.3 defines UserInfo as claims about
 * the authenticated End-User, and Spring's OidcUserInfoAuthenticationProvider refuses (`invalid_token`) an
 * authorization that has no resource owner. A client_credentials authorization has none — RFC 6749 §4.4 is the
 * client asking in its OWN name, and OAuth2Authorization::create() therefore sets `principalName` to the client
 * id — so a confidential client that registered both the grant and the `openid` scope (a combination nothing
 * forbids) would otherwise be answered 200 with `sub` = its client id, which is the identifier a relying party
 * keys accounts on. The test is the GRANT TYPE and not the presence of an id token: this server's
 * OAuth2TokenGenerator mints an id token for any grant whose scopes include `openid`, client credentials
 * included, so `token(IdToken) !== null` would pass for exactly the authorization this refuses.
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
        if ($authorization->authorizationGrantType === AuthorizationGrantType::ClientCredentials) {
            return BearerToken::challenge('The access token was not issued to an end user.');
        }

        /** @var array<string,mixed> $claims */
        $claims = is_array($token->metadata['claims'] ?? null) ? $token->metadata['claims'] : [];

        return new JsonResponse($this->mapper->map(new OidcUserInfoContext($authorization, $token, $scopes, $claims)), 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }
}
