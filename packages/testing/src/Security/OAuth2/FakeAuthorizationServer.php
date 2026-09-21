<?php

declare(strict_types=1);

namespace Firefly\Testing\Security\OAuth2;

use Firebase\JWT\JWT;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * An OpenID Connect provider in one class, for tests of firefly/security-oauth2-client and of any application
 * that signs in through it (Spring's MockOAuth2Server / the Okta mock, as a Firefly test double).
 *
 * TWO CHANNELS, TWO MECHANISMS. The FRONT channel — what a browser is redirected to — is real routes mounted
 * on the application's Router at the issuer's path: `/authorize` (auto-approving, or a consent page whose form
 * approves) and `/end-session`. They run through the same kernel and filter chain as the application, which is
 * what lets Laravel's test client AND a real Chromium (the browser suite serves the app in-process) follow the
 * redirects. The BACK channel — what the framework calls server-to-server — is Laravel's Http::fake() answering
 * exactly four URLs under the issuer (`/.well-known/openid-configuration`, `/token`, `/jwks`, `/userinfo`)
 * and NOTHING else (null lets any other request through, so a stray request is still a stray request). The
 * fake answers the real requests the code makes — the real URL, form body, client-authentication header and
 * JSON — so the code path under test is the production one minus a socket.
 *
 * WHY THE BACK CHANNEL IS NOT THE KERNEL. Re-entering Kernel::handle() from inside a request rebinds `request`
 * in the container and runs StartSession on the shared session Store, which swaps the outer request's session
 * id for a fresh one — the login that just succeeded would be written to a session the browser never gets.
 * Answering in PHP has neither hazard.
 *
 * WHAT IT ENFORCES, so a test proves the framework did the right thing: a code is single-use, bound to the
 * exact redirect_uri it was issued for, and PKCE-checked (S256) when a challenge was sent; the token endpoint
 * takes only a POST with an application/x-www-form-urlencoded body (RFC 6749 §4.1.3) — Http::post()'s default
 * JSON body and a GET carrying the parameters in the query string both get 400 invalid_request, since a real
 * provider would refuse them too and that is the most plausible mistake a token client makes; the token endpoint
 * authenticates the client by Basic header, by form body, or as a public client only when acceptPublicClient();
 * a refresh token is rotated; userinfo needs an access token it issued. Tokens are RS256 JWTs signed with a key
 * pair generated once per process (`signWithUnknownKey()` signs with a second pair under the SAME kid, which is
 * exactly a rotated-key or forged token); the clock is Laravel's Date::now(), so `$this->travel()` expires them.
 * Every hop is recorded in the public arrays, and the knobs make each failure mode reproducible — discovery
 * included, both ways it fails: `takeDiscoveryDown()` (a 503, the provider is unreachable) and
 * `overrideDiscoveryDocument()` (a 200 whose document the client must refuse, the provider is misconfigured).
 */
final class FakeAuthorizationServer
{
    public const string CLIENT_ID = 'firefly-app';

    public const string CLIENT_SECRET = 'firefly-app-secret-2f7c1c0e9b4d4c3a8e6f';

    public const string KID = 'fake-idp-2026';

    /** @var array{private: string, jwk: array<string, string>}|null */
    private static ?array $keyPair = null;

    /** @var array{private: string, jwk: array<string, string>}|null */
    private static ?array $foreignKeyPair = null;

    /** @var array<string, mixed> the user every id token and userinfo answer describes */
    private array $user = [
        'sub' => 'ada',
        'name' => 'Ada Lovelace',
        'preferred_username' => 'ada',
        'email' => 'ada@example.com',
        'email_verified' => true,
        'groups' => ['engineering'],
    ];

    private bool $consent = false;

    private int $expiresIn = 3600;

    /** @var array<string, mixed> */
    private array $idTokenClaimOverrides = [];

    private bool $signWithUnknownKey = false;

    /** @var array{0: string, 1: string}|null */
    private ?array $tokenRefusal = null;

    private ?string $authorizationRefusal = null;

    private bool $userInfo = true;

    private bool $publicClient = false;

    private bool $discoveryDown = false;

    /** @var array<string, mixed> */
    private array $discoveryDocumentOverrides = [];

    /** @var array<string, array{sub: string, scope: list<string>, nonce: ?string, redirect_uri: string, code_challenge: ?string}> */
    private array $codes = [];

    /** @var array<string, array{sub: string, scope: list<string>}> */
    private array $accessTokens = [];

    /** @var array<string, array{sub: string, scope: list<string>}> */
    private array $refreshTokens = [];

    /** @var list<array<string, string>> */
    public array $authorizationRequests = [];

    /**
     * Every token request, refused or not: `form` is the parameters as the client carried them, so a request
     * refused for its shape (a JSON body, a query string) still shows what the client tried.
     *
     * @var list<array{grant_type: string, form: array<string, string>, authorization: ?string}>
     */
    public array $tokenRequests = [];

    /** @var list<string> the bearer values presented to userinfo */
    public array $userInfoRequests = [];

    /** @var list<array<string, string>> */
    public array $endSessionRequests = [];

    public int $discoveryRequests = 0;

    public int $jwksRequests = 0;

    /** @var list<string> */
    public array $issuedIdTokens = [];

    /** @var list<string> */
    public array $issuedAccessTokens = [];

    public function __construct(
        public readonly string $issuer,
        public readonly string $clientId = self::CLIENT_ID,
        public readonly string $clientSecret = self::CLIENT_SECRET,
    ) {}

    /** Mount the front channel on the application's router and fake the back channel on its Http factory. */
    public static function install(Container $app, string $issuer, string $clientId = self::CLIENT_ID, string $clientSecret = self::CLIENT_SECRET): self
    {
        $server = new self($issuer, $clientId, $clientSecret);
        /** @var Router $router */
        $router = $app->make('router');
        /** @var HttpFactory $http */
        $http = $app->make(HttpFactory::class);

        return $server->mountFrontChannel($router)->fakeBackChannel($http);
    }

    public function mountFrontChannel(Router $router): self
    {
        $path = $this->path();
        $router->get($path.'/authorize', fn (Request $request): Response|RedirectResponse => $this->authorize($request, $request->query->all()));
        $router->post($path.'/authorize', fn (Request $request): Response|RedirectResponse => $this->authorize($request, $request->request->all()));
        $router->get($path.'/end-session', fn (Request $request): Response|RedirectResponse => $this->endSession($request));

        return $this;
    }

    public function fakeBackChannel(HttpFactory $http): self
    {
        $http->fake(fn (ClientRequest $request): ?PromiseInterface => $this->answer($request));

        return $this;
    }

    // ------------------------------------------------------------------ knobs

    /**
     * @param  array<string, mixed>  $claims  merged over the default user (sub ada, Ada Lovelace, ada@example.com, groups [engineering])
     */
    public function withUser(array $claims): self
    {
        $this->user = array_replace($this->user, $claims);

        return $this;
    }

    /** Render a consent page on GET /authorize instead of approving at once; its form POSTs the approval. */
    public function requireConsent(bool $consent = true): self
    {
        $this->consent = $consent;

        return $this;
    }

    public function expiresIn(int $seconds): self
    {
        $this->expiresIn = $seconds;

        return $this;
    }

    /**
     * Claims written over every id token from now on (a wrong nonce, a foreign issuer, another audience); a
     * null REMOVES the claim.
     *
     * @param  array<string, mixed>  $claims
     */
    public function overrideIdTokenClaims(array $claims): self
    {
        $this->idTokenClaimOverrides = $claims;

        return $this;
    }

    /** Sign with a second key pair under the same kid: a token the published JWKS cannot verify. */
    public function signWithUnknownKey(bool $unknown = true): self
    {
        $this->signWithUnknownKey = $unknown;

        return $this;
    }

    /**
     * Answer every token request with this RFC 6749 error (400) from now on. An EMPTY error clears the
     * refusal — `refuseToken('', '')` — and the endpoint answers normally again.
     */
    public function refuseToken(string $error, string $description = ''): self
    {
        $this->tokenRefusal = $error === '' ? null : [$error, $description];

        return $this;
    }

    /** Answer every authorization request with a redirect carrying this error (null approves again). */
    public function refuseAuthorization(?string $error = 'access_denied'): self
    {
        $this->authorizationRefusal = $error;

        return $this;
    }

    /** Publish no userinfo_endpoint, so a login must read the user from the id token alone. */
    public function withoutUserInfo(): self
    {
        $this->userInfo = false;

        return $this;
    }

    public function acceptPublicClient(bool $accept = true): self
    {
        $this->publicClient = $accept;

        return $this;
    }

    /** Answer discovery with a 503 from now on: the provider is down. */
    public function takeDiscoveryDown(bool $down = true): self
    {
        $this->discoveryDown = $down;

        return $this;
    }

    /**
     * Members written over the discovery document from now on (a foreign `issuer`, no `authorization_endpoint`);
     * a null REMOVES the member, and an empty array restores the real document. The other way discovery goes
     * wrong: takeDiscoveryDown() is the provider being unreachable, this is the provider answering 200 with a
     * document the client must refuse — what a wrong `issuer_uri` looks like from the application's side.
     *
     * @param  array<string, mixed>  $members
     */
    public function overrideDiscoveryDocument(array $members): self
    {
        $this->discoveryDocumentOverrides = $members;

        return $this;
    }

    // ------------------------------------------------------------- helpers

    /**
     * The `registration.{id}` block for this provider, for a test's configOverrides().
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function registrationConfig(array $overrides = []): array
    {
        return array_replace([
            'provider' => 'fake',
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
            'scope' => ['openid', 'profile', 'email'],
            'client_name' => 'Fake IdP',
        ], $overrides);
    }

    /**
     * The `provider.fake` block: the issuer, so every endpoint is discovered.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function providerConfig(string $issuer, array $overrides = []): array
    {
        return array_replace(['issuer_uri' => $issuer], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    public function discoveryDocument(): array
    {
        $base = rtrim($this->issuer, '/');
        $document = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $base.'/authorize',
            'token_endpoint' => $base.'/token',
            'jwks_uri' => $base.'/jwks',
            'end_session_endpoint' => $base.'/end-session',
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'code_challenge_methods_supported' => ['S256'],
        ];
        if ($this->userInfo) {
            $document['userinfo_endpoint'] = $base.'/userinfo';
        }

        return array_filter(array_replace($document, $this->discoveryDocumentOverrides), static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return ['keys' => [self::keyPair()['jwk']]];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function signJwt(array $claims): string
    {
        $pair = $this->signWithUnknownKey ? self::foreignKeyPair() : self::keyPair();

        return JWT::encode($claims, $pair['private'], 'RS256', self::KID);
    }

    /**
     * A code as GET /authorize would issue it — for a test that drives the token endpoint directly.
     *
     * @param  list<string>  $scopes
     */
    public function issueAuthorizationCode(string $redirectUri, array $scopes, ?string $nonce = null, ?string $codeChallenge = null): string
    {
        $code = 'code-'.bin2hex(random_bytes(16));
        $this->codes[$code] = ['sub' => $this->subject(), 'scope' => $scopes, 'nonce' => $nonce, 'redirect_uri' => $redirectUri, 'code_challenge' => $codeChallenge];

        return $code;
    }

    /** @return array<string, string> */
    public function lastAuthorizationRequest(): array
    {
        return $this->authorizationRequests[count($this->authorizationRequests) - 1] ?? [];
    }

    /** @return array{grant_type: string, form: array<string, string>, authorization: ?string} */
    public function lastTokenRequest(): array
    {
        return $this->tokenRequests[count($this->tokenRequests) - 1] ?? ['grant_type' => '', 'form' => [], 'authorization' => null];
    }

    // -------------------------------------------------------- front channel

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function authorize(Request $request, array $parameters): Response|RedirectResponse
    {
        $params = self::strings($parameters);
        $this->authorizationRequests[] = $params;

        $redirectUri = $params['redirect_uri'] ?? '';
        if (($params['client_id'] ?? '') !== $this->clientId || $redirectUri === '') {
            return new Response('Fake IdP: unknown client or no redirect_uri.', 400, ['Content-Type' => 'text/plain']);
        }
        $state = $params['state'] ?? null;
        if (($params['response_type'] ?? '') !== 'code') {
            return $this->redirectWithError($redirectUri, 'unsupported_response_type', $state);
        }
        if ($this->authorizationRefusal !== null) {
            return $this->redirectWithError($redirectUri, $this->authorizationRefusal, $state);
        }
        if ($this->consent && $request->isMethod('GET')) {
            return $this->consentPage($request, $params);
        }

        $code = $this->issueAuthorizationCode($redirectUri, self::scopes($params['scope'] ?? ''), $params['nonce'] ?? null, $params['code_challenge'] ?? null);

        return new RedirectResponse(self::append($redirectUri, $state === null ? ['code' => $code] : ['code' => $code, 'state' => $state]));
    }

    /**
     * @param  array<string, string>  $params
     */
    private function consentPage(Request $request, array $params): Response
    {
        $fields = '';
        foreach ($params as $name => $value) {
            $fields .= '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">';
        }
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Fake IdP · Consent</title></head><body>'
            .'<h1>Fake IdP</h1><p>'.self::e($this->clientId).' asks to sign you in as '.self::e($this->subject()).'.</p>'
            .'<form method="post" action="'.self::e($request->getBaseUrl().$this->path().'/authorize').'">'.$fields
            .'<button type="submit" name="approve" value="1">Allow</button></form></body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function endSession(Request $request): Response|RedirectResponse
    {
        $params = self::strings($request->query->all());
        $this->endSessionRequests[] = $params;

        $to = $params['post_logout_redirect_uri'] ?? '';
        if ($to !== '') {
            return new RedirectResponse(self::append($to, isset($params['state']) ? ['state' => $params['state']] : []));
        }

        return new Response('<!DOCTYPE html><html lang="en"><body><h1>Fake IdP</h1><p>You have signed out of the provider.</p></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // --------------------------------------------------------- back channel

    private function answer(ClientRequest $request): ?PromiseInterface
    {
        $url = strtok($request->url(), '?');
        if ($url === false) {
            return null;
        }
        $base = rtrim($this->issuer, '/');

        return match ($url) {
            $base.'/.well-known/openid-configuration' => $this->discovery(),
            $base.'/token' => $this->token($request),
            $base.'/jwks' => $this->keys(),
            $base.'/userinfo' => $this->userInfo($request),
            default => null,
        };
    }

    private function discovery(): PromiseInterface
    {
        $this->discoveryRequests++;

        return $this->discoveryDown
            ? HttpFactory::response('', 503)
            : HttpFactory::response($this->discoveryDocument(), 200);
    }

    private function keys(): PromiseInterface
    {
        $this->jwksRequests++;

        return HttpFactory::response($this->jwks(), 200);
    }

    private function token(ClientRequest $request): PromiseInterface
    {
        $formPost = self::isFormPost($request);
        $form = $formPost ? self::formBody($request) : self::strings($request->data());
        $authorization = self::firstHeader($request, 'Authorization');
        $grant = $form['grant_type'] ?? '';
        $this->tokenRequests[] = ['grant_type' => $grant, 'form' => $form, 'authorization' => $authorization];

        // The shape first, before any knob: ClientRequest::data() reads a JSON body or, on a GET, the query
        // string just as happily as a form body, and a token client that sends either is broken however the
        // fake was configured — a real provider answers 400 to both.
        if (! $formPost) {
            return $this->error(400, 'invalid_request', 'The token request must be a POST with an application/x-www-form-urlencoded body (RFC 6749 §4.1.3).');
        }
        if ($this->tokenRefusal !== null) {
            return $this->error(400, $this->tokenRefusal[0], $this->tokenRefusal[1]);
        }
        if (! $this->clientAuthenticated($form, $authorization)) {
            return $this->error(401, 'invalid_client', 'Client authentication failed.');
        }

        return match ($grant) {
            'authorization_code' => $this->exchangeCode($form),
            'refresh_token' => $this->refresh($form),
            'client_credentials' => $this->issue($this->clientId, self::scopes($form['scope'] ?? ''), null, false),
            default => $this->error(400, 'unsupported_grant_type', "The grant type [{$grant}] is not supported."),
        };
    }

    /**
     * @param  array<string, string>  $form
     */
    private function clientAuthenticated(array $form, ?string $authorization): bool
    {
        if ($authorization !== null && strncasecmp($authorization, 'Basic ', 6) === 0) {
            $decoded = base64_decode(trim(substr($authorization, 6)), true);
            if ($decoded === false || ! str_contains($decoded, ':')) {
                return false;
            }
            [$id, $secret] = explode(':', $decoded, 2);

            return hash_equals($this->clientId, rawurldecode($id)) && hash_equals($this->clientSecret, rawurldecode($secret));
        }
        if (($form['client_id'] ?? '') !== $this->clientId) {
            return false;
        }
        if (isset($form['client_secret'])) {
            return hash_equals($this->clientSecret, $form['client_secret']);
        }

        return $this->publicClient;
    }

    /**
     * @param  array<string, string>  $form
     */
    private function exchangeCode(array $form): PromiseInterface
    {
        $code = $form['code'] ?? '';
        $issued = $this->codes[$code] ?? null;
        unset($this->codes[$code]); // single use, whatever happens next
        if ($issued === null) {
            return $this->error(400, 'invalid_grant', 'The authorization code is unknown or was already used.');
        }
        if (($form['redirect_uri'] ?? '') !== $issued['redirect_uri']) {
            return $this->error(400, 'invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        if ($issued['code_challenge'] !== null) {
            $verifier = $form['code_verifier'] ?? '';
            if ($verifier === '' || ! hash_equals($issued['code_challenge'], self::base64url(hash('sha256', $verifier, true)))) {
                return $this->error(400, 'invalid_grant', 'PKCE verification failed.');
            }
        }

        return $this->issue($issued['sub'], $issued['scope'], $issued['nonce'], true);
    }

    /**
     * @param  array<string, string>  $form
     */
    private function refresh(array $form): PromiseInterface
    {
        $token = $form['refresh_token'] ?? '';
        $issued = $this->refreshTokens[$token] ?? null;
        unset($this->refreshTokens[$token]); // rotation
        if ($issued === null) {
            return $this->error(400, 'invalid_grant', 'The refresh token is unknown or was already used.');
        }

        return $this->issue($issued['sub'], $issued['scope'], null, true);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issue(string $sub, array $scopes, ?string $nonce, bool $refreshable): PromiseInterface
    {
        $now = Date::now()->getTimestamp();
        $accessToken = $this->signJwt(['iss' => $this->issuer, 'sub' => $sub, 'aud' => $this->clientId, 'iat' => $now, 'exp' => $now + $this->expiresIn, 'scope' => implode(' ', $scopes), 'jti' => bin2hex(random_bytes(8))]);
        $this->accessTokens[$accessToken] = ['sub' => $sub, 'scope' => $scopes];
        $this->issuedAccessTokens[] = $accessToken;

        $body = ['access_token' => $accessToken, 'token_type' => 'Bearer', 'expires_in' => $this->expiresIn];
        if ($scopes !== []) {
            $body['scope'] = implode(' ', $scopes);
        }
        if (in_array('openid', $scopes, true)) {
            $claims = ['iss' => $this->issuer, 'sub' => $sub, 'aud' => $this->clientId, 'azp' => $this->clientId, 'iat' => $now, 'exp' => $now + $this->expiresIn, ...$this->userClaims()];
            if ($nonce !== null) {
                $claims['nonce'] = $nonce;
            }
            $claims = array_filter(array_replace($claims, $this->idTokenClaimOverrides), static fn (mixed $value): bool => $value !== null);
            $idToken = $this->signJwt($claims);
            $body['id_token'] = $idToken;
            $this->issuedIdTokens[] = $idToken;
        }
        if ($refreshable) {
            $refresh = 'rt-'.bin2hex(random_bytes(16));
            $this->refreshTokens[$refresh] = ['sub' => $sub, 'scope' => $scopes];
            $body['refresh_token'] = $refresh;
        }

        return HttpFactory::response($body, 200);
    }

    private function userInfo(ClientRequest $request): PromiseInterface
    {
        $authorization = self::firstHeader($request, 'Authorization') ?? '';
        $bearer = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        $this->userInfoRequests[] = $bearer;

        $issued = $this->accessTokens[$bearer] ?? null;
        if ($issued === null) {
            return HttpFactory::response(['error' => 'invalid_token'], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        }

        return HttpFactory::response(['sub' => $issued['sub'], ...$this->userClaims()], 200);
    }

    // -------------------------------------------------------------- utilities

    private function error(int $status, string $error, string $description): PromiseInterface
    {
        return HttpFactory::response(['error' => $error, 'error_description' => $description], $status);
    }

    private function redirectWithError(string $redirectUri, string $error, ?string $state): RedirectResponse
    {
        return new RedirectResponse(self::append($redirectUri, $state === null ? ['error' => $error] : ['error' => $error, 'state' => $state]));
    }

    /**
     * The user's claims minus `sub` (the code's subject wins).
     *
     * @return array<string, mixed>
     */
    private function userClaims(): array
    {
        $claims = $this->user;
        unset($claims['sub']);

        return $claims;
    }

    private function subject(): string
    {
        $sub = $this->user['sub'] ?? null;

        return is_scalar($sub) ? (string) $sub : 'ada';
    }

    /** The issuer's path, where the front channel is mounted (`/fake-idp` for `http://localhost/fake-idp`). */
    private function path(): string
    {
        $path = parse_url($this->issuer, PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    private static function firstHeader(ClientRequest $request, string $name): ?string
    {
        /** @var array<int, mixed> $values */
        $values = $request->header($name);
        $first = $values[0] ?? null;

        return is_string($first) ? $first : null;
    }

    /**
     * A POST whose Content-Type media type is application/x-www-form-urlencoded — read off the wire headers,
     * not ClientRequest::isForm(), which wants the header verbatim and would refuse a `; charset=UTF-8` suffix
     * that any real provider accepts.
     */
    private static function isFormPost(ClientRequest $request): bool
    {
        if ($request->method() !== 'POST') {
            return false;
        }
        $mediaType = strtok(self::firstHeader($request, 'Content-Type') ?? '', ';');

        return $mediaType !== false && strtolower(trim($mediaType)) === 'application/x-www-form-urlencoded';
    }

    /**
     * The form parameters off the wire body — not ClientRequest::data(), which parses the body only under the
     * verbatim header and otherwise falls back to what PendingRequest remembered, nothing for a raw withBody().
     *
     * @return array<string, string>
     */
    private static function formBody(ClientRequest $request): array
    {
        parse_str($request->body(), $parameters);

        return self::strings($parameters);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $name => $value) {
            if (is_scalar($value)) {
                $strings[(string) $name] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * @return list<string>
     */
    private static function scopes(string $scope): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param  array<string, string>  $query
     */
    private static function append(string $uri, array $query): string
    {
        if ($query === []) {
            return $uri;
        }

        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($query);
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array{private: string, jwk: array<string, string>}
     */
    private static function keyPair(): array
    {
        return self::$keyPair ??= self::generateKeyPair();
    }

    /**
     * @return array{private: string, jwk: array<string, string>}
     */
    private static function foreignKeyPair(): array
    {
        return self::$foreignKeyPair ??= self::generateKeyPair();
    }

    /**
     * @return array{private: string, jwk: array<string, string>}
     */
    private static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('The fake authorization server could not generate its RSA key pair.');
        }
        $pem = '';
        if (! openssl_pkey_export($key, $pem) || ! is_string($pem)) {
            throw new RuntimeException('The fake authorization server could not export its private key.');
        }
        $details = openssl_pkey_get_details($key);
        $rsa = $details === false ? null : ($details['rsa'] ?? null);
        if (! is_array($rsa) || ! is_string($rsa['n'] ?? null) || ! is_string($rsa['e'] ?? null)) {
            throw new RuntimeException('The fake authorization server could not read its RSA modulus and exponent.');
        }

        return [
            'private' => $pem,
            'jwk' => ['kty' => 'RSA', 'kid' => self::KID, 'use' => 'sig', 'alg' => 'RS256', 'n' => self::base64url($rsa['n']), 'e' => self::base64url($rsa['e'])],
        ];
    }
}
