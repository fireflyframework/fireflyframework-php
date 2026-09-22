<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session security AND the CSRF filter on, through the real pipeline: the -80 filter is global and runs
 * ahead of the `web` group, so on a session-backed request it — not Laravel's PreventRequestForgery — is
 * what answers a browser client. The XSRF-TOKEN cookie comes from Laravel's own middleware on a GET route,
 * encrypted by the global EncryptCookies on the way out, so the header value each POST echoes is exactly
 * the ciphertext a browser holds and Axios sends.
 */
abstract class SessionCsrfCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.session.enabled' => true,
            'firefly.security.csrf.enabled' => true,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // What a Laravel `web` route does for a SPA: PreventRequestForgery sets XSRF-TOKEN to the session
        // token on every response (it validates nothing on a GET, and nothing at all under a test runner).
        Route::get('/open/spa', static fn (Request $request): array => ['token' => $request->session()->token()])
            ->middleware(PreventRequestForgery::class);

        Route::post('/open/echo', static fn (): array => ['echoed' => true]);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    public function xsrfCookieAsTheBrowserHoldsIt(TestResponse $response): string
    {
        $cookie = $response->getCookie('XSRF-TOKEN', false);
        if ($cookie === null) {
            throw new RuntimeException('The response set no XSRF-TOKEN cookie.');
        }

        return (string) $cookie->getValue();
    }
}

uses(SessionCsrfCapstoneTestCase::class);

it('accepts a POST whose X-XSRF-TOKEN echoes the encrypted XSRF-TOKEN cookie, as a Laravel SPA client sends it', function () {
    /** @var SessionCsrfCapstoneTestCase $this */
    $page = $this->get('/open/spa');
    $page->assertOk();

    $ciphertext = $this->xsrfCookieAsTheBrowserHoldsIt($page);
    /** @var string $token */
    $token = $page->json('token');

    // The cookie on the wire is ciphertext, not the token — the filter has to decrypt it to compare.
    expect($ciphertext)->not->toBe($token)
        ->and($ciphertext)->not->toContain($token);

    $this->forgetSession();
    $this->followSession($page)
        ->withHeader('X-XSRF-TOKEN', $ciphertext)
        ->postJson('/open/echo')
        ->assertOk()
        ->assertJson(['echoed' => true]);
});

it('accepts the X-CSRF-TOKEN header and the _token field against the session token', function () {
    /** @var SessionCsrfCapstoneTestCase $this */
    $page = $this->get('/open/spa');
    /** @var string $token */
    $token = $page->json('token');

    $this->forgetSession();
    $this->followSession($page)->withHeader('X-CSRF-TOKEN', $token)->postJson('/open/echo')->assertOk();

    $this->forgetSession();
    $this->flushHeaders();
    $this->followSession($page)->post('/open/echo', ['_token' => $token])->assertOk();
});

it('refuses a POST with no token, a plain-token X-XSRF-TOKEN, a garbage one, and one from another session', function () {
    /** @var SessionCsrfCapstoneTestCase $this */
    $page = $this->get('/open/spa');
    /** @var string $token */
    $token = $page->json('token');

    // A second, unrelated session — as a new process would start it, with no cookie and no in-memory store.
    $this->forgetSession();
    $other = $this->forgetCookies()->get('/open/spa');
    expect($other->json('token'))->not->toBe($token);

    $this->forgetSession();
    $this->forgetCookies()->followSession($page)->postJson('/open/echo')->assertStatus(403);

    $this->forgetSession();
    $this->followSession($page)->withHeader('X-XSRF-TOKEN', $token)->postJson('/open/echo')->assertStatus(403);

    $this->forgetSession();
    $this->followSession($page)->withHeader('X-XSRF-TOKEN', 'not-a-ciphertext')->postJson('/open/echo')->assertStatus(403);

    $this->forgetSession();
    $this->followSession($page)->withHeader('X-XSRF-TOKEN', $this->xsrfCookieAsTheBrowserHoldsIt($other))->postJson('/open/echo')->assertStatus(403);
});
