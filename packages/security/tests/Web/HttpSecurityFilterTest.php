<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\HttpSecurity;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Web\HttpSecurityFilter;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

function httpFilter(): HttpSecurityFilter
{
    $rules = HttpSecurity::create()
        ->requestMatcher('api/public/*')->permitAll()
        ->requestMatcher('api/admin/*')->hasRole('ADMIN')
        ->anyRequest()->authenticated();
    // NOTE (deviation from brief): HttpSecurityFilter::shouldNotFilter() is AND-gated on BOTH the master
    // `firefly.security.enabled` flag AND `firefly.security.http.enabled` (F2 defense-in-depth patch — its
    // ctor deps are master-gated beans). The brief's test config only set `http.enabled`, which would make the
    // filter inert (shouldNotFilter() short-circuits true) and every assertion below would silently no-op /
    // fail. Both flags are set here so the filter actually runs.
    $config = new Config(new Repository(['firefly' => ['security' => ['enabled' => true, 'http' => ['enabled' => true]]]]));

    return new HttpSecurityFilter($rules, new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new DenyAllPermissionEvaluator, $config);
}

function asAdmin(): void
{
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('root', 'root', [new SimpleGrantedAuthority('ROLE_ADMIN')])
    ));
}

it('permits a permitAll path even when anonymous', function () {
    $out = httpFilter()->handle(Request::create('/api/public/ping', 'GET'), fn () => new Response('ok'));
    expect($out)->toBeInstanceOf(Response::class);
});

it('401s an anonymous request to a protected path', function () {
    httpFilter()->handle(Request::create('/api/admin/users', 'GET'), fn () => new Response('ok'));
})->throws(AuthenticationException::class);

it('403s an authenticated-but-insufficient request', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('bob', 'bob', [new SimpleGrantedAuthority('ROLE_USER')])
    ));
    httpFilter()->handle(Request::create('/api/admin/users', 'GET'), fn () => new Response('ok'));
})->throws(AuthorizationException::class);

it('allows an admin through the admin path', function () {
    asAdmin();
    $out = httpFilter()->handle(Request::create('/api/admin/users', 'GET'), fn () => new Response('ok'));
    expect($out)->toBeInstanceOf(Response::class);
});
