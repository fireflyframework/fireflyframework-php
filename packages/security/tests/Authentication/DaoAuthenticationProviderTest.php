<?php

declare(strict_types=1);

use Firefly\Security\Authentication\DaoAuthenticationProvider;
use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Authentication\Exception\DisabledException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Password\NoOpPasswordEncoder;
use Firefly\Security\User\InMemoryUserDetailsService;
use Firefly\Security\User\User;

function daoProvider(bool $enabled = true): DaoAuthenticationProvider
{
    $users = new InMemoryUserDetailsService([
        new User('alice', 'pw', [new SimpleGrantedAuthority('ROLE_ADMIN')], $enabled),
    ]);

    return new DaoAuthenticationProvider($users, new NoOpPasswordEncoder);
}

it('authenticates valid credentials and erases them', function () {
    $result = daoProvider()->authenticate(Authentication::unauthenticated('alice', 'alice', 'pw'));

    expect($result->isAuthenticated())->toBeTrue()
        ->and($result->getCredentials())->toBeNull()
        ->and($result->authorityStrings())->toBe(['ROLE_ADMIN']);
});

it('rejects a wrong password with BadCredentialsException (401)', function () {
    daoProvider()->authenticate(Authentication::unauthenticated('alice', 'alice', 'nope'));
})->throws(BadCredentialsException::class);

it('rejects a disabled account with DisabledException (401)', function () {
    daoProvider(enabled: false)->authenticate(Authentication::unauthenticated('alice', 'alice', 'pw'));
})->throws(DisabledException::class);
