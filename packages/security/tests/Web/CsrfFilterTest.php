<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Web\CsrfFilter;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/**
 * @param  list<string>  $except
 */
function csrfFilter(array $except = []): CsrfFilter
{
    return new CsrfFilter(new Config(new Repository(['firefly' => ['security' => ['csrf' => ['enabled' => true, 'except' => $except]]]])));
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
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();

    $request = Request::create('/x', 'POST', ['_token' => $store->token()], cookies: ['XSRF-TOKEN' => 'ignored']);
    $request->setLaravelSession($store);

    expect(csrfFilter()->handle($request, fn () => new Response('ok')))->toBeInstanceOf(Response::class);

    $wrong = Request::create('/x', 'POST', ['_token' => 'not-the-token'], cookies: ['XSRF-TOKEN' => 'ignored']);
    $wrong->headers->set('X-XSRF-TOKEN', 'ignored');
    $wrong->setLaravelSession($store);

    expect(fn () => csrfFilter()->handle($wrong, fn () => new Response('ok')))->toThrow(AuthorizationException::class);
});
