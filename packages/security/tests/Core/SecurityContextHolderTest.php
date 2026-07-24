<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Support\Facades\Context;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('defaults to an anonymous context when unset', function () {
    expect(SecurityContextHolder::getContext()->isAuthenticated())->toBeFalse()
        ->and(SecurityContextHolder::getAuthentication())->toBeNull();
});

it('stores and returns the current context', function () {
    $auth = Authentication::authenticated('alice', 'alice', [new SimpleGrantedAuthority('ROLE_USER')]);
    SecurityContextHolder::setContext(new SecurityContext($auth));

    expect(SecurityContextHolder::getContext()->isAuthenticated())->toBeTrue()
        ->and(SecurityContextHolder::getAuthentication()?->getName())->toBe('alice');
});

it('clears the context so a fresh request reads anonymous (no bleed)', function () {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('alice', 'alice', [])));
    SecurityContextHolder::clearContext();

    // Simulate the next Octane request: Context flushed.
    Context::flush();

    expect(SecurityContextHolder::getAuthentication())->toBeNull();
});
