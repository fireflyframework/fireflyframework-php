<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Web\CsrfFilter;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
