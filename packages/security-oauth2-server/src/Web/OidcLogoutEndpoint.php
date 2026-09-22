<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\Web\Logout\LogoutHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET|POST {oidc_logout_endpoint} — OpenID Connect RP-Initiated Logout 1.0 (Spring's OidcLogoutEndpointFilter):
 * a relying party sends the browser here with the id token it holds. The hint is verified with the server's
 * keys (its `exp` ignored — a session usually outlives its id token), must name this issuer and a registered
 * client in `aud`; `client_id`, when sent, must be that client; `post_logout_redirect_uri`, when sent, must be
 * one the client registered (exact); and a signed-in principal other than the hint's `sub` is refused, so a
 * link cannot end someone else's session. Then the browser is sent to the redirect URI (with `state`) or to `/`.
 * Refusals are the RFC 6749 JSON document (400): there is nowhere safe to redirect a request that failed these
 * checks.
 *
 * ENDING THE SESSION IS NOT THIS CLASS'S BUSINESS. It hands the request and the redirect to firefly/security's
 * LogoutHandler — the same bean LogoutFilter's `POST {logout_url}` hands them to, which is Spring's arrangement
 * (one SecurityContextLogoutHandler behind both LogoutFilter and OidcLogoutEndpointFilter). So the remember-me
 * cookie, every `firefly.security.logout.delete_cookies` name, `invalidate_session` and `clear_authentication`
 * govern RP-initiated logout exactly as they govern the form logout, and a key added to LogoutSettings reaches
 * both paths without anyone remembering to come back here.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class OidcLogoutEndpoint implements OAuth2Endpoint
{
    public function __construct(
        private readonly AuthorizationServerSettings $settings,
        private readonly JwtGenerator $jwt,
        private readonly RegisteredClientRepository $clients,
        private readonly LogoutHandler $logout,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function methods(): array
    {
        return ['GET', 'POST'];
    }

    public function answersJson(): bool
    {
        return false;
    }

    public function handle(Request $request): Response
    {
        $hint = $request->input('id_token_hint');
        if (! is_string($hint) || $hint === '') {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'id_token_hint is required.'));
        }

        try {
            $claims = $this->jwt->decodeIgnoringExpiry($hint);
        } catch (Throwable) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN, 'id_token_hint could not be verified.'));
        }
        if (($claims['iss'] ?? null) !== $this->settings->issuer) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN, 'id_token_hint was not issued by this server.'));
        }

        $client = $this->audienceClient($claims['aud'] ?? null);
        if ($client === null) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN, 'id_token_hint names no registered client.'));
        }

        $clientId = $request->input('client_id');
        if (is_string($clientId) && $clientId !== '' && $clientId !== $client->clientId) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'client_id does not match the id_token_hint.'));
        }

        $redirectUri = $request->input('post_logout_redirect_uri');
        if (is_string($redirectUri) && $redirectUri !== '' && ! $client->hasPostLogoutRedirectUri($redirectUri)) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'post_logout_redirect_uri is not registered for the client.'));
        }

        $authentication = SecurityContextHolder::getAuthentication();
        if ($authentication !== null && SecurityContextHolder::getContext()->isAuthenticated() && $authentication->getName() !== ($claims['sub'] ?? null)) {
            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN, 'id_token_hint names another user than the one signed in.'));
        }

        $target = is_string($redirectUri) && $redirectUri !== '' ? $redirectUri : $request->getUriForPath('/');
        $state = $request->input('state');
        if (is_string($state) && $state !== '') {
            $target = OAuth2ErrorResponse::withQuery($target, ['state' => $state]);
        }
        $response = new RedirectResponse($target);

        $this->logout->logout($request, $response, $authentication);
        $this->logger?->info('OAuth2 RP-initiated logout by client ['.$client->clientId.'] for ['.($authentication?->getName() ?? (is_string($claims['sub'] ?? null) ? $claims['sub'] : 'unknown')).'].');

        return $response;
    }

    private function audienceClient(mixed $aud): ?RegisteredClient
    {
        $audiences = is_array($aud) ? $aud : [$aud];
        foreach ($audiences as $audience) {
            if (is_string($audience) && ($client = $this->clients->findByClientId($audience)) !== null) {
                return $client;
            }
        }

        return null;
    }
}
