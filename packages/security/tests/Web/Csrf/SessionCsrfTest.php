<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function sessionCsrfEncrypter(): Encrypter
{
    return new Encrypter(random_bytes(32), 'AES-256-CBC');
}

function sessionCsrf(Encrypter $encrypter): SessionCsrf
{
    $container = new Container;
    $container->instance(EncrypterContract::class, $encrypter);

    return new SessionCsrf($container);
}

function sessionCsrfStore(): Store
{
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();

    return $store;
}

function xsrfCiphertext(Encrypter $encrypter, string $token): string
{
    return $encrypter->encrypt(CookieValuePrefix::create('XSRF-TOKEN', $encrypter->getKey()).$token, false);
}

/**
 * @param  array<string, string>  $body
 * @param  array<string, string>  $headers
 */
function sessionCsrfRequest(?Store $store, array $body = [], array $headers = []): Request
{
    $request = Request::create('/login', 'POST', $body);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    if ($store !== null) {
        $request->setLaravelSession($store);
    }

    return $request;
}

it('refuses a request with no session at all', function () {
    sessionCsrf(sessionCsrfEncrypter())->verify(sessionCsrfRequest(null, ['_token' => 'anything']));
})->throws(AuthorizationException::class, 'CSRF token mismatch.');

it('accepts the _token form field, the X-CSRF-TOKEN header and the encrypted X-XSRF-TOKEN header, in that order of precedence', function () {
    $encrypter = sessionCsrfEncrypter();
    $store = sessionCsrfStore();
    $csrf = sessionCsrf($encrypter);

    $csrf->verify(sessionCsrfRequest($store, ['_token' => $store->token()]));
    $csrf->verify(sessionCsrfRequest($store, [], ['X-CSRF-TOKEN' => $store->token()]));
    $csrf->verify(sessionCsrfRequest($store, [], ['X-XSRF-TOKEN' => xsrfCiphertext($encrypter, $store->token())]));

    // The field wins over the headers, exactly as in PreventRequestForgery::getTokenFromRequest(): a wrong
    // field is a mismatch even when a header beside it would have matched.
    expect(fn () => $csrf->verify(sessionCsrfRequest($store, ['_token' => 'wrong'], ['X-CSRF-TOKEN' => $store->token()])))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $csrf->verify(sessionCsrfRequest($store, [], ['X-CSRF-TOKEN' => 'wrong', 'X-XSRF-TOKEN' => xsrfCiphertext($encrypter, $store->token())])))
        ->toThrow(AuthorizationException::class);
});

it('falls through an empty field or header to the next source, as Laravel does', function () {
    $encrypter = sessionCsrfEncrypter();
    $store = sessionCsrfStore();

    sessionCsrf($encrypter)->verify(sessionCsrfRequest($store, ['_token' => ''], ['X-CSRF-TOKEN' => '', 'X-XSRF-TOKEN' => xsrfCiphertext($encrypter, $store->token())]));

    expect(true)->toBeTrue();
});

it('treats an X-XSRF-TOKEN that does not decrypt, decrypts under another key, or carries the plain token as a mismatch, never an exception of its own', function () {
    $encrypter = sessionCsrfEncrypter();
    $store = sessionCsrfStore();
    $csrf = sessionCsrf($encrypter);

    foreach ([
        'garbage' => 'definitely-not-a-laravel-payload',
        'valid base64, not a payload' => base64_encode('{"iv":"x","value":"y","mac":"z"}'),
        'another key' => xsrfCiphertext(sessionCsrfEncrypter(), $store->token()),
        'another token' => xsrfCiphertext($encrypter, 'someone-else'),
        'plain token' => $store->token(),
        'ciphertext without the prefix' => $encrypter->encrypt($store->token(), false),
    ] as $case => $header) {
        expect(fn () => $csrf->verify(sessionCsrfRequest($store, [], ['X-XSRF-TOKEN' => $header])))
            ->toThrow(AuthorizationException::class, 'CSRF token mismatch.', $case);
    }
});

it('refuses a request presenting no token through any source', function () {
    sessionCsrf(sessionCsrfEncrypter())->verify(sessionCsrfRequest(sessionCsrfStore()));
})->throws(AuthorizationException::class, 'CSRF token mismatch.');

it('never resolves the Encrypter unless an X-XSRF-TOKEN has to be decrypted', function () {
    // The bean is built at every boot — `php artisan key:generate` included, when APP_KEY is still empty
    // and the Encrypter cannot be built at all — so the Encrypter must be a first-use lookup, never a
    // constructor dependency; and a form or script that sends _token / X-CSRF-TOKEN never needs it.
    $container = new Container;
    $container->bind(EncrypterContract::class, static fn (): never => throw new MissingAppKeyException);
    $store = sessionCsrfStore();

    $csrf = new SessionCsrf($container);
    $csrf->verify(sessionCsrfRequest($store, ['_token' => $store->token()]));
    $csrf->verify(sessionCsrfRequest($store, [], ['X-CSRF-TOKEN' => $store->token()]));

    expect(fn () => $csrf->verify(sessionCsrfRequest($store, [], ['X-XSRF-TOKEN' => 'anything'])))
        ->toThrow(MissingAppKeyException::class);
});
