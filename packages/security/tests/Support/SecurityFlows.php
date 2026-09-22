<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three things every browser flow needs and Laravel's test client does not do by itself: carry the
 * session cookie from one response into the next request, know that cookie's name, and read the CSRF token
 * off a rendered form. The cookie value TestResponse::getCookie() returns is already decrypted, and
 * withCookie() encrypts it again on the way in — so the round trip is the real one, through EncryptCookies.
 *
 * Composed by SecurityCapstoneTestCase, so every suite built on it has these; a Pest file may also name it in
 * uses() beside its capstone class, which is legal and changes nothing.
 *
 * The helpers are PUBLIC, not protected: Pest 4 types `$this` inside an it() closure as the TestCall, so a
 * protected call is `method.protected` to PHPStan — the same convention FireflyTestCase::app() follows.
 */
trait SecurityFlows
{
    /**
     * Carry the response's session cookie on every following request, INCLUDING the JSON ones: Laravel's
     * getJson()/postJson() send no cookies at all unless withCredentials() was called (the same opt-in a
     * browser's fetch() needs), and a flow that reads /whoami as JSON after signing in would otherwise be
     * answered for a brand-new session.
     *
     * @param  TestResponse<Response>  $response
     */
    public function followSession(TestResponse $response): static
    {
        $cookie = $response->getCookie($this->sessionCookieName());
        if ($cookie === null) {
            throw new RuntimeException('The response carried no session cookie to follow.');
        }

        return $this->withCredentials()->withCookie($this->sessionCookieName(), (string) $cookie->getValue());
    }

    public function sessionCookieName(): string
    {
        /** @var string $name */
        $name = $this->app()->make('config')->get('session.cookie');

        return $name;
    }

    /**
     * Drop every cookie the client would send: withCookie() persists for the whole test, so "a request without
     * the session cookie" has to be asked for explicitly.
     */
    public function forgetCookies(): static
    {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    public function csrfTokenFrom(TestResponse $response): string
    {
        if (preg_match('/name="_token" value="([^"]+)"/', (string) $response->getContent(), $match) !== 1) {
            throw new RuntimeException('The page carries no _token field.');
        }

        return $match[1];
    }
}
