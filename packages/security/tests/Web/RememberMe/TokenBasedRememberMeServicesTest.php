<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\User\InMemoryUserDetailsService;
use Firefly\Security\User\User;
use Firefly\Security\Web\RememberMe\TokenBasedRememberMeServices;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

function rememberMe(bool $alwaysRemember = false, int $validity = 1209600): TokenBasedRememberMeServices
{
    $users = new InMemoryUserDetailsService([
        new User('ada', '{bcrypt}$2y$04$abcdefghijklmnopqrstuv', [new SimpleGrantedAuthority('ROLE_USER')]),
        new User('off', '{bcrypt}$2y$04$abcdefghijklmnopqrstuv', [], enabled: false),
    ]);

    return new TokenBasedRememberMeServices(new RememberMeSettings(enabled: true, key: str_repeat('r', 40), tokenValiditySeconds: $validity, alwaysRemember: $alwaysRemember), $users);
}

function adaToken(): Authentication
{
    $ada = new User('ada', '{bcrypt}$2y$04$abcdefghijklmnopqrstuv', [new SimpleGrantedAuthority('ROLE_USER')]);

    return Authentication::authenticated('ada', $ada, $ada->getAuthorities());
}

it('sets a signed cookie only when asked, and re-authenticates from it', function () {
    $services = rememberMe();

    $quiet = new Response;
    $services->loginSuccess(Request::create('/login', 'POST', ['username' => 'ada']), $quiet, adaToken());
    expect($quiet->headers->getCookies())->toBe([]);

    $response = new Response;
    $services->loginSuccess(Request::create('/login', 'POST', ['remember-me' => '1']), $response, adaToken());
    $cookie = $response->headers->getCookies()[0];

    expect($cookie->getName())->toBe('remember-me')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getExpiresTime())->toBeGreaterThan(time() + 1209000);

    $parts = TokenBasedRememberMeServices::decode((string) $cookie->getValue());
    expect($parts)->not->toBeNull()
        ->and($parts[0] ?? null)->toBe('ada');

    $back = $services->autoLogin(Request::create('/home', 'GET', cookies: ['remember-me' => (string) $cookie->getValue()]));

    expect($back?->getName())->toBe('ada')
        ->and($back?->isAuthenticated())->toBeTrue()
        ->and($back?->authorityStrings())->toBe(['ROLE_USER']);
});

it('always remembers when configured to, and expires the cookie on logout', function () {
    $response = new Response;
    rememberMe(alwaysRemember: true)->loginSuccess(Request::create('/login', 'POST'), $response, adaToken());

    expect($response->headers->getCookies())->toHaveCount(1);

    $logout = new Response;
    rememberMe()->logout(Request::create('/logout', 'POST'), $logout);
    $expired = $logout->headers->getCookies()[0];

    expect($expired->getName())->toBe('remember-me')
        ->and($expired->getValue())->toBe('')
        ->and($expired->getExpiresTime())->toBeLessThan(time());
});

it('refuses a missing, malformed, expired, mis-signed, unknown or disabled token — all as null, never an exception', function () {
    $services = rememberMe();
    $request = static fn (?string $cookie): Request => Request::create('/home', 'GET', cookies: $cookie === null ? [] : ['remember-me' => $cookie]);
    $signed = static function (string $username, int $expiry, string $key = ''): string {
        $key = $key === '' ? str_repeat('r', 40) : $key;
        $hash = $username === 'off' || $username === 'ada' ? '{bcrypt}$2y$04$abcdefghijklmnopqrstuv' : 'x';

        return TokenBasedRememberMeServices::encode($username, $expiry, hash_hmac('sha256', $username.':'.$expiry.':'.$hash, $key));
    };

    expect($services->autoLogin($request(null)))->toBeNull()
        ->and($services->autoLogin($request('not-base64!')))->toBeNull()
        ->and($services->autoLogin($request(base64_encode('only:two'))))->toBeNull()
        ->and($services->autoLogin($request($signed('ada', time() - 1))))->toBeNull()
        ->and($services->autoLogin($request($signed('ada', time() + 3600, str_repeat('k', 40)))))->toBeNull()
        ->and($services->autoLogin($request($signed('ghost', time() + 3600))))->toBeNull()
        ->and($services->autoLogin($request($signed('off', time() + 3600))))->toBeNull()
        ->and($services->autoLogin($request($signed('ada', time() + 3600)))?->getName())->toBe('ada');
});

it('invalidates every outstanding cookie when the password hash changes', function () {
    $response = new Response;
    rememberMe()->loginSuccess(Request::create('/login', 'POST', ['remember-me' => 'on']), $response, adaToken());
    $cookie = (string) $response->headers->getCookies()[0]->getValue();

    $rotated = new TokenBasedRememberMeServices(
        new RememberMeSettings(enabled: true, key: str_repeat('r', 40)),
        new InMemoryUserDetailsService([new User('ada', '{bcrypt}$2y$04$ROTATEDROTATEDROTATEDRO', [])]),
    );

    expect($rotated->autoLogin(Request::create('/home', 'GET', cookies: ['remember-me' => $cookie])))->toBeNull();
});
