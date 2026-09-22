<?php

declare(strict_types=1);

namespace Firefly\Testing\Security\OAuth2;

use Firefly\Security\OAuth2\Server\Pkce\ProofKey;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the application's OWN authorization server through Laravel's test client, from any FireflyTestCase
 * (Spring Authorization Server's tests do this with MockMvc): an authorization request with a fresh PKCE pair
 * and state, the consent form when the page appears, the code off the redirect, the token exchange, client
 * credentials, refresh, introspection, revocation and userinfo. The endpoint paths come from the
 * AuthorizationServerSettings bean, so a renamed endpoint needs no change here.
 *
 * SIGN THE USER IN THROUGH THE LOGIN PAGE FIRST — the framework's form, or whatever the application's own
 * mechanism is — and carry the session cookie (`followSession()`). `actingAsPrincipal()` is NOT enough at the
 * authorization endpoint and is not meant to be: it establishes a principal in SecurityContextHolder for the
 * one request, while the endpoint answers a browser and demands the principal the SecurityContextRepository
 * holds BETWEEN requests, so that no bearer, no test double and no filter of an application's own can be the
 * resource owner without a session behind it (AuthorizationEndpoint::sessionHeldPrincipal()). A holder-only
 * principal is answered with the login redirect, exactly as an anonymous browser is. The token, introspection,
 * revocation and userinfo helpers are machine endpoints and need no sign-in at all.
 *
 * A confidential client authenticates with `client_secret_basic` (withBasicAuth), a public one with `client_id`
 * in the body; the Basic header is flushed after every call so it never leaks into the test's next request.
 */
final class OAuth2ServerTestClient
{
    private ?ProofKey $proof = null;

    private ?string $state = null;

    public function __construct(private readonly FireflyTestCase $test) {}

    public function settings(): AuthorizationServerSettings
    {
        /** @var AuthorizationServerSettings */
        return $this->test->app()->make(AuthorizationServerSettings::class);
    }

    /** The PKCE pair of the last authorize(), for a hand-built token request. */
    public function proofKey(): ProofKey
    {
        return $this->proof ?? throw new LogicException('authorize() has not been called yet, so there is no proof key.');
    }

    public function state(): string
    {
        return $this->state ?? throw new LogicException('authorize() has not been called yet, so there is no state.');
    }

    /**
     * GET {authorization_endpoint} with response_type=code, a fresh S256 challenge and state; $extra adds or
     * overrides parameters (`nonce`, `prompt`, `max_age`, or `code_challenge` to send a wrong one).
     *
     * @param  array<string,string>  $extra
     * @return TestResponse<Response>
     */
    public function authorize(string $clientId, string $redirectUri, string $scope = 'openid', array $extra = []): TestResponse
    {
        $this->proof = ProofKey::generate();
        $this->state = bin2hex(random_bytes(8));
        $query = $extra + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $this->state,
            'code_challenge' => $this->proof->challenge(),
            'code_challenge_method' => 'S256',
        ];

        return $this->test->get($this->settings()->authorizationEndpoint.'?'.http_build_query(array_filter($query, static fn (string $v): bool => $v !== '')));
    }

    /**
     * Submit the consent page: every requested scope unless $scopes narrows it, the session token and the pending
     * state read off the page, with the session cookie carried.
     *
     * @param  TestResponse<Response>  $page
     * @param  list<string>|null  $scopes
     * @return TestResponse<Response>
     */
    public function approveConsent(TestResponse $page, ?array $scopes = null): TestResponse
    {
        $html = (string) $page->getContent();
        if (preg_match('/name="_token" value="([^"]+)"/', $html, $token) !== 1) {
            throw new RuntimeException('The consent page carries no _token field.');
        }
        if (preg_match('/name="state" value="([^"]+)"/', $html, $state) !== 1) {
            throw new RuntimeException('The consent page carries no state field.');
        }
        if ($scopes === null) {
            preg_match_all('/name="scope\[\]" value="([^"]+)"/', $html, $found);
            $scopes = $found[1];
        }

        return $this->followSession($page)->post($this->settings()->authorizationEndpoint, ['_token' => $token[1], 'state' => $state[1], 'scope' => $scopes, 'action' => 'approve']);
    }

    /**
     * @param  array<string,string>  $extra
     */
    public function obtainCode(string $clientId, string $redirectUri, string $scope = 'openid', array $extra = []): string
    {
        $response = $this->authorize($clientId, $redirectUri, $scope, $extra);
        if ($response->getStatusCode() === 200) {
            $response = $this->approveConsent($response);
        }

        return self::codeFrom($response);
    }

    /**
     * @param  TestResponse<Response>  $redirect
     */
    public static function codeFrom(TestResponse $redirect): string
    {
        $location = (string) $redirect->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        if (! is_string($code) || $code === '') {
            throw new RuntimeException("The response is not a redirect carrying a code: status {$redirect->getStatusCode()}, Location [{$location}].");
        }

        return $code;
    }

    /**
     * @return TestResponse<Response>
     */
    public function exchangeCode(string $clientId, string $code, string $redirectUri, ?string $clientSecret = null): TestResponse
    {
        return $this->token($clientId, $clientSecret, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $this->proofKey()->verifier,
        ]);
    }

    /**
     * @return TestResponse<Response>
     */
    public function clientCredentials(string $clientId, string $clientSecret, string $scope = ''): TestResponse
    {
        return $this->token($clientId, $clientSecret, array_filter(['grant_type' => 'client_credentials', 'scope' => $scope], static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return TestResponse<Response>
     */
    public function refresh(string $clientId, string $refreshToken, ?string $clientSecret = null, string $scope = ''): TestResponse
    {
        return $this->token($clientId, $clientSecret, array_filter(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'scope' => $scope], static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return TestResponse<Response>
     */
    public function introspect(string $clientId, string $clientSecret, string $token, ?string $hint = null): TestResponse
    {
        return $this->post($this->settings()->tokenIntrospectionEndpoint, $clientId, $clientSecret, array_filter(['token' => $token, 'token_type_hint' => $hint]));
    }

    /**
     * @return TestResponse<Response>
     */
    public function revoke(string $clientId, string $clientSecret, string $token, ?string $hint = null): TestResponse
    {
        return $this->post($this->settings()->tokenRevocationEndpoint, $clientId, $clientSecret, array_filter(['token' => $token, 'token_type_hint' => $hint]));
    }

    /**
     * @return TestResponse<Response>
     */
    public function userInfo(string $accessToken): TestResponse
    {
        $response = $this->test->withHeader('Authorization', 'Bearer '.$accessToken)->getJson($this->settings()->oidcUserInfoEndpoint);
        $this->test->flushHeaders();

        return $response;
    }

    /**
     * authorize (+ consent) and exchange in one go; the decoded token response.
     *
     * @return array<string,mixed>
     */
    public function tokens(string $clientId, string $redirectUri, string $scope = 'openid', ?string $clientSecret = null): array
    {
        $code = $this->obtainCode($clientId, $redirectUri, $scope);
        $response = $this->exchangeCode($clientId, $code, $redirectUri, $clientSecret);
        $response->assertOk();

        /** @var array<string,mixed> */
        return $response->json();
    }

    /**
     * POST {token_endpoint} as the client: Basic credentials for a confidential client, `client_id` in the body
     * for a public one.
     *
     * @param  array<string,mixed>  $body
     * @return TestResponse<Response>
     */
    public function token(string $clientId, ?string $clientSecret, array $body): TestResponse
    {
        return $this->post($this->settings()->tokenEndpoint, $clientId, $clientSecret, $body);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return TestResponse<Response>
     */
    private function post(string $path, string $clientId, ?string $clientSecret, array $body): TestResponse
    {
        if ($clientSecret === null) {
            $body['client_id'] = $clientId;
            $response = $this->test->post($path, $body, ['Accept' => 'application/json']);
        } else {
            $response = $this->test->withBasicAuth($clientId, $clientSecret)->post($path, $body, ['Accept' => 'application/json']);
            $this->test->flushHeaders();
        }

        return $response;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function followSession(TestResponse $response): FireflyTestCase
    {
        /** @var string $name */
        $name = $this->test->app()->make('config')->get('session.cookie');
        $cookie = $response->getCookie($name);
        if ($cookie === null) {
            throw new RuntimeException('The consent page set no session cookie to carry into the consent POST.');
        }

        return $this->test->withCredentials()->withCookie($name, (string) $cookie->getValue());
    }
}
