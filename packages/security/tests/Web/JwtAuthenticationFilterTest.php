<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Security\InvalidTokenException;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Web\JwtAuthenticationFilter;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @return array{0: JwtAuthenticationFilter, 1: JwtService}
 */
function jwtFilter(): array
{
    $jwt = new JwtService(str_repeat('k', 40));
    $config = new Config(new Repository(['firefly' => ['security' => ['jwt' => ['enabled' => true]]]]));

    return [new JwtAuthenticationFilter($jwt, $config), $jwt];
}

it('establishes the SecurityContext from a valid Bearer token, then clears it on exit', function () {
    [$filter, $jwt] = jwtFilter();
    $token = $jwt->encode(['sub' => 'alice', 'authorities' => ['ROLE_ADMIN']], 3600);

    $request = Request::create('/api/x', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$token);

    $seen = null;
    $filter->handle($request, function () use (&$seen) {
        $seen = SecurityContextHolder::getAuthentication();

        return new Response('ok');
    });

    expect($seen?->getName())->toBe('alice')
        ->and($seen?->authorityStrings())->toBe(['ROLE_ADMIN'])
        ->and(SecurityContextHolder::getAuthentication())->toBeNull(); // cleared on exit
});

it('falls back to anonymous when no Authorization header is present', function () {
    [$filter] = jwtFilter();
    $seen = 'unset';
    $filter->handle(Request::create('/api/x', 'GET'), function () use (&$seen) {
        $seen = SecurityContextHolder::getAuthentication();

        return new Response('ok');
    });

    expect($seen)->toBeNull();
});

it('propagates a 401 for a present-but-invalid token', function () {
    [$filter] = jwtFilter();
    $request = Request::create('/api/x', 'GET');
    $request->headers->set('Authorization', 'Bearer not-a-jwt');

    $filter->handle($request, fn () => new Response('ok'));
})->throws(InvalidTokenException::class);
