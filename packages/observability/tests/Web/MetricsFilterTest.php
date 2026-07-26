<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Web\MetricsFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;

it('records a timer tagged with method, status and SUCCESS outcome', function () {
    $registry = new SimpleMeterRegistry;
    $filter = new MetricsFilter($registry);

    $request = Request::create('/balances/7', 'GET');
    $filter->handle($request, fn () => new Response('ok', 200));

    $timer = $registry->timer('http_server_requests_seconds', [
        'method' => 'GET', 'uri' => '/balances/7', 'status' => '200', 'outcome' => 'SUCCESS', 'exception' => 'none',
    ]);

    expect($timer->count())->toBe(1);
});

it('records a SERVER_ERROR outcome with the exception class and rethrows', function () {
    $registry = new SimpleMeterRegistry;
    $filter = new MetricsFilter($registry);
    $request = Request::create('/boom', 'GET');

    expect(fn () => $filter->handle($request, function () {
        throw new RuntimeException('x');
    }))
        ->toThrow(RuntimeException::class);

    $recorded = array_filter($registry->meters(), fn ($m) => ($m->tags()['exception'] ?? '') === 'RuntimeException');
    expect($recorded)->not->toBeEmpty();
});

it('uses the templated route uri (bounded cardinality) instead of the raw path when a route is matched', function () {
    $registry = new SimpleMeterRegistry;
    $filter = new MetricsFilter($registry);

    $request = Request::create('/balances/7', 'GET');
    $route = new Route('GET', 'balances/{id}', []);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    $filter->handle($request, fn () => new Response('ok', 200));

    $timer = $registry->timer('http_server_requests_seconds', [
        'method' => 'GET', 'uri' => '/balances/{id}', 'status' => '200', 'outcome' => 'SUCCESS', 'exception' => 'none',
    ]);

    expect($timer->count())->toBe(1);
});
