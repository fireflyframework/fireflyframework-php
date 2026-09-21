<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Security\Web\CsrfFilter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/**
 * @param  list<string>  $except
 */
function csrfFilter(array $except = [], ?Encrypter $encrypter = null): CsrfFilter
{
    $container = new Container;
    $container->instance(EncrypterContract::class, $encrypter ?? new Encrypter(random_bytes(32), 'AES-256-CBC'));

    return new CsrfFilter(
        new Config(new Repository(['firefly' => ['security' => ['csrf' => ['enabled' => true, 'except' => $except]]]])),
        new SessionCsrf($container),
    );
}

function startedSession(): Store
{
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();

    return $store;
}

/**
 * The XSRF-TOKEN cookie exactly as the browser holds it and Axios sends it back: EncryptCookies encrypts the
 * token with the per-cookie-name prefix in front, and the client echoes that ciphertext verbatim.
 */
function encryptedXsrfCookie(Encrypter $encrypter, string $token): string
{
    return $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).$token, false);
}

it('lets safe methods through without a token', function () {
    $out = csrfFilter()->handle(Request::create('/x', 'GET'), fn () => new Response('ok'));
    expect($out)->toBeInstanceOf(Response::class);
});

it('passes an unsafe method when header matches cookie', function () {
    $request = Request::create('/x', 'POST', cookies: ['XSRF-TOKEN' => 'tok123']);
    $request->headers->set('X-XSRF-TOKEN', 'tok123');

    $out = csrfFilter()->handle($request, fn () => new Response('ok'));
    expect($out)->toBeInstanceOf(Response::class);
});

it('403s an unsafe method with a missing or mismatched token', function () {
    $request = Request::create('/x', 'POST', cookies: ['XSRF-TOKEN' => 'tok123']);
    $request->headers->set('X-XSRF-TOKEN', 'WRONG');

    csrfFilter()->handle($request, fn () => new Response('ok'));
})->throws(AuthorizationException::class);

it('exempts a configured path', function () {
    $out = csrfFilter(except: ['webhooks/*'])->handle(Request::create('/webhooks/stripe', 'POST'), fn () => new Response('ok'));
    expect($out)->toBeInstanceOf(Response::class);
});

it('403s an unsafe method with no cookie and no header at all (canonical double-submit bypass)', function () {
    $request = Request::create('/x', 'POST');

    csrfFilter()->handle($request, fn () => new Response('ok'));
})->throws(AuthorizationException::class);

it('prefers the session token over the double-submit cookie when the request has a session', function () {
    $store = startedSession();

    $request = Request::create('/x', 'POST', ['_token' => $store->token()], cookies: ['XSRF-TOKEN' => 'ignored']);
    $request->setLaravelSession($store);

    expect(csrfFilter()->handle($request, fn () => new Response('ok')))->toBeInstanceOf(Response::class);

    $wrong = Request::create('/x', 'POST', ['_token' => 'not-the-token'], cookies: ['XSRF-TOKEN' => 'ignored']);
    $wrong->headers->set('X-XSRF-TOKEN', 'ignored');
    $wrong->setLaravelSession($store);

    expect(fn () => csrfFilter()->handle($wrong, fn () => new Response('ok')))->toThrow(AuthorizationException::class);
});

it('accepts the X-CSRF-TOKEN header against the session token', function () {
    $store = startedSession();

    $request = Request::create('/x', 'POST');
    $request->headers->set('X-CSRF-TOKEN', $store->token());
    $request->setLaravelSession($store);

    expect(csrfFilter()->handle($request, fn () => new Response('ok')))->toBeInstanceOf(Response::class);
});

it('accepts X-XSRF-TOKEN carrying the encrypted XSRF-TOKEN cookie on the session path, as a standard Laravel SPA client sends it', function () {
    // Laravel's PreventRequestForgery sets XSRF-TOKEN to the session token, EncryptCookies encrypts it, and
    // Axios echoes the ciphertext back in X-XSRF-TOKEN — with session security on, the -80 filter is the
    // one that answers that request, so it has to read the header exactly as Laravel would.
    $encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $store = startedSession();

    $request = Request::create('/x', 'POST', cookies: ['XSRF-TOKEN' => 'the-browser-sends-the-ciphertext-here-too']);
    $request->headers->set('X-XSRF-TOKEN', encryptedXsrfCookie($encrypter, $store->token()));
    $request->setLaravelSession($store);

    expect(csrfFilter(encrypter: $encrypter)->handle($request, fn () => new Response('ok')))->toBeInstanceOf(Response::class);
});

it('403s an X-XSRF-TOKEN that is garbage, one that does not decrypt to the session token, and one carrying the plain token', function () {
    $encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $store = startedSession();

    foreach ([
        'garbage' => 'not-a-ciphertext',
        'another token' => encryptedXsrfCookie($encrypter, 'not-the-session-token'),
        'another key' => encryptedXsrfCookie(new Encrypter(random_bytes(32), 'AES-256-CBC'), $store->token()),
        'plain token' => $store->token(),
    ] as $case => $header) {
        $request = Request::create('/x', 'POST');
        $request->headers->set('X-XSRF-TOKEN', $header);
        $request->setLaravelSession($store);

        expect(fn () => csrfFilter(encrypter: $encrypter)->handle($request, fn () => new Response('ok')))
            ->toThrow(AuthorizationException::class, 'CSRF token mismatch.', $case);
    }
});
