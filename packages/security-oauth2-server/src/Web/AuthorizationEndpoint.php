<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsent;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Error\InvalidAuthorizationRequestException;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;
use Firefly\Security\OAuth2\Server\Pkce\ProofKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Token\OAuth2TokenGenerator;
use Firefly\Security\OAuth2\Server\Web\Consent\ConsentPage;
use Firefly\Security\OAuth2\Server\Web\Consent\ConsentPageModel;
use Firefly\Security\OAuth2\Server\Web\Consent\PendingConsent;
use Firefly\Security\OAuth2\Server\Web\Consent\SessionAuthenticationTime;
use Firefly\Security\Session\SavedRequest;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Security\Web\EntryPoint\AuthenticationEntryPoint;
use Firefly\Security\Web\EntryPoint\LoginUrlAuthenticationEntryPoint;
use Firefly\Security\Web\RememberMe\RememberMeServices;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET {authorization_endpoint} — RFC 6749 §4.1.1 with PKCE (RFC 7636) and OpenID Connect Core §3.1.2 — and the
 * POST of the consent form (Spring's OAuth2AuthorizationEndpointFilter), in RFC order:
 *
 *   1. UNREDIRECTABLE (§4.1.2.1): no or unknown `client_id`, or a `redirect_uri` the client did not register
 *      (exact match; when the client registered exactly one it may be omitted) — the resource owner is SHOWN
 *      the error (InvalidAuthorizationRequestException, firefly/web's 400), never redirected.
 *   2. REDIRECTABLE: `response_type` other than `code`, a client without the authorization_code grant, a scope
 *      the client did not register (no scope asks for every registered one), PKCE missing when the server, the
 *      client or its public nature demands it, `plain`, a malformed challenge, a non-numeric `max_age` — a 302
 *      to the redirect URI with `error`, `error_description` and the echoed `state`.
 *   3. THE PRINCIPAL — the one the SecurityContextRepository HOLDS between requests, never merely whatever
 *      SecurityContextHolder carries at -82 (see sessionHeldPrincipal(): a bearer the resource-server filter
 *      authenticated at -85 is not a resource owner) — anonymous, or `prompt=login`, or `max_age` exceeded
 *      (measured from the instant SessionAuthenticationTimeListener stamped at an ACTIVE sign-in — a session the
 *      remember-me cookie signed in has none and exceeds any `max_age`; one that signed in before the listener
 *      could see it is stamped on this first look, see SessionAuthenticationTime) → `login_required` for
 *      `prompt=none`, otherwise the entry point (the bean when HttpSecurityFilter is on; a
 *      LoginUrlAuthenticationEntryPoint over the form-login settings when it is not; a plain 401 when form login
 *      is off) with the request saved WITHOUT `login` among its prompts — whether the browser was anonymous or is
 *      being asked again, since the return visit is signed in either way and would otherwise read the prompt as
 *      a demand to sign in once more. When it IS a signed-in browser being asked again, the stored context and
 *      the instant are cleared and the remember-me cookie is expired on the redirect (the cookie would otherwise
 *      sign the browser straight back in on the return trip, no credential entered — the filter at -83 runs
 *      ahead of this endpoint's), so the sign-in that follows is a credentialed one, stamped afresh, that comes
 *      back here once (`max_age` still on the request, now satisfied) and proceeds.
 *   4. CONSENT: when the client requires it and (`prompt=consent`, or no stored consent covers the requested
 *      scopes) → `consent_required` for `prompt=none`, otherwise the page (the framework's, or `consent.view`
 *      with the same model, falling back logged at warning like the login view) with the request pending in
 *      the session under a random state.
 *   5. THE CODE: an OAuth2Authorization carrying the request (`redirect_uri`, `scope`, `state`, the challenge
 *      and method, `nonce`, `auth_time`, `sid`) and a single-use code (the client's `authorization_code_ttl`),
 *      saved, then `302 {redirect_uri}?code=…&state=…`.
 *
 * The POST verifies the session token through SessionCsrf FIRST (a forged consent is an attack, so this cannot
 * depend on CsrfFilter, which runs later), consumes the pending request the `state` names (a stale or foreign
 * state is the 400 page), requires the same session-held principal (the same rule as the GET, so a bearer cannot
 * approve a consent the browser left pending either), and either records the consent (merged with the stored
 * one) and issues the code for the approved scopes, or redirects with `access_denied`.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class AuthorizationEndpoint implements OAuth2Endpoint
{
    public function __construct(
        private readonly AuthorizationServerSettings $settings,
        private readonly RegisteredClientRepository $clients,
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly OAuth2AuthorizationConsentService $consents,
        private readonly SessionCsrf $csrf,
        private readonly FormLoginSettings $formLogin,
        private readonly SecurityContextRepository $contexts,
        private readonly ErrorPageSettings $pages,
        private readonly Container $container,
        private readonly ?AuthenticationEntryPoint $entryPoint = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RememberMeServices $rememberMe = null,
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
        if ($request->isMethod('POST')) {
            return $this->consentSubmitted($request);
        }

        $authorizationRequest = AuthorizationRequest::from($request);
        $client = $authorizationRequest->clientId === '' ? null : $this->clients->findByClientId($authorizationRequest->clientId);
        if ($client === null) {
            throw new InvalidAuthorizationRequestException('The authorization request names no registered client.');
        }

        $redirectUri = $authorizationRequest->redirectUri ?? (count($client->redirectUris) === 1 ? $client->redirectUris[0] : null);
        if ($redirectUri === null || ! $client->hasRedirectUri($redirectUri)) {
            throw new InvalidAuthorizationRequestException('The redirect_uri is missing or is not one the client registered.');
        }

        try {
            $scopes = $this->validate($authorizationRequest, $client);
        } catch (OAuth2AuthenticationException $e) {
            return OAuth2ErrorResponse::redirect($redirectUri, $e->error(), $authorizationRequest->state);
        }

        $principal = $this->sessionHeldPrincipal($request);
        $reauthenticate = $principal !== null && ($authorizationRequest->prompts('login') || $this->maxAgeExceeded($request, $authorizationRequest));

        if ($principal === null || $reauthenticate) {
            if ($authorizationRequest->prompts('none')) {
                return OAuth2ErrorResponse::redirect($redirectUri, new OAuth2Error(OAuth2ErrorCodes::LOGIN_REQUIRED, 'The user is not signed in.'), $authorizationRequest->state);
            }

            return $this->commenceLogin($request, $authorizationRequest, $reauthenticate);
        }

        $consent = $this->consents->findById($client->id, $principal->getName());
        $needsConsent = $client->clientSettings->requireAuthorizationConsent
            && ($authorizationRequest->prompts('consent') || $consent === null || ! $consent->hasScopes($scopes));

        if ($needsConsent) {
            if ($authorizationRequest->prompts('none')) {
                return OAuth2ErrorResponse::redirect($redirectUri, new OAuth2Error(OAuth2ErrorCodes::CONSENT_REQUIRED, 'The user has not consented to these scopes.'), $authorizationRequest->state);
            }

            return $this->consentPage($request, $client, $authorizationRequest, $redirectUri, $scopes, $consent, $principal);
        }

        return $this->issueCode($request, $client, $principal, $scopes, [
            'redirect_uri' => $redirectUri,
            'scope' => $authorizationRequest->scope,
            'state' => $authorizationRequest->state,
            'code_challenge' => $authorizationRequest->codeChallenge,
            'code_challenge_method' => $authorizationRequest->codeChallengeMethod,
            'nonce' => $authorizationRequest->nonce,
        ]);
    }

    /**
     * The redirectable checks, in RFC order; the requested scopes (or every registered one) on success.
     *
     * @return list<string>
     *
     * @throws OAuth2AuthenticationException
     */
    private function validate(AuthorizationRequest $authorizationRequest, RegisteredClient $client): array
    {
        if ($authorizationRequest->responseType === null) {
            throw $this->refuse(OAuth2ErrorCodes::INVALID_REQUEST, 'response_type is required.');
        }
        if ($authorizationRequest->responseType !== 'code') {
            throw $this->refuse(OAuth2ErrorCodes::UNSUPPORTED_RESPONSE_TYPE, 'Only response_type=code is supported.');
        }
        if (! $client->supportsGrant(AuthorizationGrantType::AuthorizationCode)) {
            throw $this->refuse(OAuth2ErrorCodes::UNAUTHORIZED_CLIENT, 'The client is not registered for the authorization_code grant.');
        }

        $scopes = $authorizationRequest->scopes();
        if ($scopes === []) {
            $scopes = $client->scopes;
        } elseif (! $client->hasScopes($scopes)) {
            throw $this->refuse(OAuth2ErrorCodes::INVALID_SCOPE, 'The request asks for a scope the client did not register.');
        }

        if ($authorizationRequest->codeChallenge !== null) {
            if ($authorizationRequest->codeChallengeMethod !== 'S256') {
                throw $this->refuse(OAuth2ErrorCodes::INVALID_REQUEST, 'code_challenge_method must be S256.');
            }
            if (! ProofKey::isWellFormed($authorizationRequest->codeChallenge)) {
                throw $this->refuse(OAuth2ErrorCodes::INVALID_REQUEST, 'code_challenge is malformed.');
            }
        } elseif ($client->requiresProofKey($this->settings)) {
            throw $this->refuse(OAuth2ErrorCodes::INVALID_REQUEST, 'code_challenge is required (PKCE, S256).');
        }

        if ($authorizationRequest->maxAgeMalformed) {
            throw $this->refuse(OAuth2ErrorCodes::INVALID_REQUEST, 'max_age must be a number of seconds.');
        }

        return $scopes;
    }

    /**
     * THE RESOURCE OWNER IS THE PRINCIPAL THE REPOSITORY HOLDS BETWEEN REQUESTS, not merely whatever
     * SecurityContextHolder carries when this endpoint runs at -82. A filter ahead of it may have authenticated
     * something that is not a person in front of a browser: OAuth2ResourceServerFilter (-85) authenticates any
     * bearer, and the shape this package documents — the server is the resource server for its own keys — turns
     * that filter on, so an access token this very server issued arrives here already authenticated. Left
     * unchecked, such a token would BE the resource owner: a stolen or narrowly scoped one would redeem itself
     * for a fresh code — wider scopes, a new refresh token, an `auth_time` stamped on a session nobody signed
     * into — at any client that needs no consent or was consented to once, with no browser and nobody present.
     * A test's actingAsPrincipal() and an application filter that sets only the holder are the same shape.
     *
     * So the holder's principal counts only when the SecurityContextRepository — the session, or whatever an
     * application bound in its place — holds an authenticated context under the SAME name: SecurityContextPersistenceFilter
     * (-94) read it from the browser's session, or RememberMeAuthenticationFilter (-83) stored it on the way
     * through. Anything else is anonymous HERE and nowhere else (the bearer still authenticates the API call it
     * was minted for): the login redirect, or `login_required` for `prompt=none`. A sign-in mechanism of an
     * application's own is answered like any browser as soon as it stores its context through the repository,
     * which is what every shipped filter does.
     */
    private function sessionHeldPrincipal(Request $request): ?Authentication
    {
        $principal = SecurityContextHolder::getAuthentication();
        if ($principal === null || ! SecurityContextHolder::getContext()->isAuthenticated()) {
            return null;
        }

        $stored = $this->contexts->load($request);
        $held = $stored === null || ! $stored->isAuthenticated() ? null : $stored->getAuthentication();

        return $held !== null && $held->getName() === $principal->getName() ? $principal : null;
    }

    /**
     * `max_age` against the active sign-in instant the listener stamped (OpenID Connect Core §3.1.2.1); a session
     * the remember-me cookie signed in has no such instant and exceeds any `max_age`. Without a session there is
     * nothing to measure from, so the check cannot fail — a stateless deployment cannot honour `max_age`.
     */
    private function maxAgeExceeded(Request $request, AuthorizationRequest $authorizationRequest): bool
    {
        if ($authorizationRequest->maxAge === null || ! $request->hasSession()) {
            return false;
        }

        $authenticatedAt = SessionAuthenticationTime::ofSessionHeldPrincipal($request->session());

        return $authenticatedAt === null || $authenticatedAt + $authorizationRequest->maxAge < time();
    }

    /**
     * Send the browser to sign in and come back. Whenever the request carries `prompt=login` the request the entry
     * point saved is rewritten without that one prompt (`consent` and the rest stay), so the return visit — signed
     * in by then, whether the browser was anonymous now or is being asked again — proceeds instead of reading the
     * prompt as a demand to sign in a second time. For a re-authentication ($reauthenticate: a signed-in browser
     * with `prompt=login` or an exceeded `max_age`) the stored context and the instant are cleared FIRST, and the
     * remember-me cookie is expired on the redirect through the same port logout uses: left alone, the cookie
     * would sign the browser back in on its very next request, and the fresh sign-in this endpoint asked for
     * would never involve a credential.
     */
    private function commenceLogin(Request $request, AuthorizationRequest $authorizationRequest, bool $reauthenticate): Response
    {
        if ($reauthenticate && $request->hasSession()) {
            $this->contexts->clear($request);
            SessionAuthenticationTime::forget($request->session());
            SecurityContextHolder::clearContext();
        }

        $entryPoint = $this->entryPoint ?? ($this->formLogin->enabled ? new LoginUrlAuthenticationEntryPoint($this->formLogin) : null);
        if ($entryPoint === null) {
            throw new AuthenticationException('Authentication is required to authorize a client.');
        }

        $response = $entryPoint->commence($request, new AuthenticationException('Authentication is required to authorize a client.'));

        if ($reauthenticate) {
            $this->rememberMe?->logout($request, $response);
        }

        if ($authorizationRequest->prompts('login') && $request->hasSession() && $request->session()->get(SavedRequest::KEY) === $request->fullUrl()) {
            $request->session()->put(SavedRequest::KEY, $this->withoutLoginPrompt($request, $authorizationRequest));
        }

        return $response;
    }

    /** The request's own URL with `login` taken out of `prompt` — the parameter dropped when it was the only value. */
    private function withoutLoginPrompt(Request $request, AuthorizationRequest $authorizationRequest): string
    {
        $query = $request->query->all();
        $prompts = array_values(array_diff($authorizationRequest->prompt, ['login']));
        if ($prompts === []) {
            unset($query['prompt']);
        } else {
            $query['prompt'] = implode(' ', $prompts);
        }

        return $request->url().($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param  list<string>  $scopes
     */
    private function consentPage(Request $request, RegisteredClient $client, AuthorizationRequest $authorizationRequest, string $redirectUri, array $scopes, ?OAuth2AuthorizationConsent $consent, Authentication $principal): Response
    {
        $state = bin2hex(random_bytes(16));
        PendingConsent::store($request->session(), $state, [
            'client' => $client->id,
            'principal' => $principal->getName(),
            'scopes' => $scopes,
            'redirect_uri' => $redirectUri,
            'scope' => $authorizationRequest->scope,
            'client_state' => $authorizationRequest->state,
            'code_challenge' => $authorizationRequest->codeChallenge,
            'code_challenge_method' => $authorizationRequest->codeChallengeMethod,
            'nonce' => $authorizationRequest->nonce,
        ]);

        $model = new ConsentPageModel(
            title: $this->pages->title,
            clientName: $client->clientName,
            clientId: $client->clientId,
            principalName: $principal->getName(),
            scopes: array_map(static fn (string $scope): array => ['scope' => $scope, 'description' => ConsentPage::describe($scope), 'approved' => $consent !== null && $consent->hasScopes([$scope])], $scopes),
            state: $state,
            action: $request->getBaseUrl().FormLoginSettings::path($this->settings->authorizationEndpoint),
            csrfToken: $request->session()->token(),
        );

        return new HttpResponse($this->renderConsent($model), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** The configured view when it exists and renders, the framework page otherwise — never silently. */
    private function renderConsent(ConsentPageModel $consent): string
    {
        $view = $this->settings->consentView;
        if ($view === null) {
            return ConsentPage::render($consent);
        }

        $views = $this->optional(ViewFactory::class);
        if ($views === null) {
            $this->logger?->warning("The consent view [{$view}] cannot be rendered because no view factory is bound; the framework page was served instead.", ['view' => $view]);

            return ConsentPage::render($consent);
        }

        try {
            if ($views->exists($view)) {
                return $views->make($view, ['consent' => $consent])->render();
            }
            $this->logger?->warning("The consent view [{$view}] does not exist; the framework page was served instead.", ['view' => $view]);
        } catch (Throwable $e) {
            $this->logger?->warning("The consent view [{$view}] failed to render; the framework page was served instead: {$e->getMessage()}", ['view' => $view, 'exception' => $e]);
        }

        return ConsentPage::render($consent);
    }

    private function consentSubmitted(Request $request): Response
    {
        $this->csrf->verify($request);

        $state = $request->input('state');
        $pending = is_string($state) && $request->hasSession() ? PendingConsent::consume($request->session(), $state) : null;
        if ($pending === null) {
            throw new InvalidAuthorizationRequestException('The consent submission does not match a pending authorization request.');
        }

        $principal = $this->sessionHeldPrincipal($request);
        if ($principal === null || $principal->getName() !== ($pending['principal'] ?? null)) {
            throw new InvalidAuthorizationRequestException('The consent submission does not belong to the signed-in user.');
        }

        $client = is_string($pending['client'] ?? null) ? $this->clients->findById($pending['client']) : null;
        $redirectUri = $pending['redirect_uri'] ?? null;
        if ($client === null || ! is_string($redirectUri)) {
            throw new InvalidAuthorizationRequestException('The pending authorization request names no registered client.');
        }
        $clientState = is_string($pending['client_state'] ?? null) ? $pending['client_state'] : null;

        /** @var list<string> $requested */
        $requested = is_array($pending['scopes'] ?? null) ? array_values(array_filter($pending['scopes'], 'is_string')) : [];
        $submitted = $request->input('scope', []);
        $approved = array_values(array_intersect($requested, is_array($submitted) ? array_filter($submitted, 'is_string') : []));

        if ($request->input('action') !== 'approve' || $approved === []) {
            return OAuth2ErrorResponse::redirect($redirectUri, new OAuth2Error(OAuth2ErrorCodes::ACCESS_DENIED, 'The resource owner denied the request.'), $clientState);
        }

        $existing = $this->consents->findById($client->id, $principal->getName());
        $this->consents->save($existing === null ? new OAuth2AuthorizationConsent($client->id, $principal->getName(), $approved) : $existing->withScopes($approved));

        return $this->issueCode($request, $client, $principal, $approved, [
            'redirect_uri' => $redirectUri,
            'scope' => $pending['scope'] ?? null,
            'state' => $clientState,
            'code_challenge' => $pending['code_challenge'] ?? null,
            'code_challenge_method' => $pending['code_challenge_method'] ?? null,
            'nonce' => $pending['nonce'] ?? null,
        ]);
    }

    /**
     * The authorization with the code and the request's attributes; `auth_time` is the active sign-in instant the
     * listener stamped (the code-issue instant only for a session the listener never saw; none at all for one the
     * remember-me cookie signed in, see SessionAuthenticationTime) and `sid` the session id, both omitted for a
     * request without a session.
     *
     * @param  list<string>  $scopes
     * @param  array<string,mixed>  $attributes
     */
    private function issueCode(Request $request, RegisteredClient $client, Authentication $principal, array $scopes, array $attributes): RedirectResponse
    {
        $now = new DateTimeImmutable;
        if ($request->hasSession()) {
            $attributes['auth_time'] = SessionAuthenticationTime::ofSessionHeldPrincipal($request->session());
            $attributes['sid'] = $request->session()->getId();
        }

        $code = OAuth2TokenGenerator::opaque();
        $authorization = OAuth2Authorization::create($client, $principal->getName(), AuthorizationGrantType::AuthorizationCode, $scopes, array_filter($attributes, static fn (mixed $v): bool => $v !== null))
            ->withToken(OAuth2Token::issue(OAuth2TokenType::AuthorizationCode, $code, $now, $now->modify("+{$client->tokenSettings->authorizationCodeTtl} seconds")));
        $this->authorizations->save($authorization);
        $this->logger?->info("OAuth2 authorization code issued to client [{$client->clientId}] for [{$principal->getName()}].");

        $params = ['code' => $code];
        $state = $attributes['state'] ?? null;
        if (is_string($state) && $state !== '') {
            $params['state'] = $state;
        }
        /** @var string $redirectUri */
        $redirectUri = $attributes['redirect_uri'];

        return new RedirectResponse(OAuth2ErrorResponse::withQuery($redirectUri, $params));
    }

    private function refuse(string $code, string $description): OAuth2AuthenticationException
    {
        return new OAuth2AuthenticationException(new OAuth2Error($code, $description));
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T|null
     */
    private function optional(string $abstract): ?object
    {
        if (! $this->container->bound($abstract)) {
            return null;
        }
        try {
            $service = $this->container->make($abstract);
        } catch (BindingResolutionException) {
            return null;
        }

        return $service instanceof $abstract ? $service : null;
    }
}
