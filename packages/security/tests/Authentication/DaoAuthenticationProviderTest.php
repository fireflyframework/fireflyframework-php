<?php

declare(strict_types=1);

use Firefly\Security\Authentication\DaoAuthenticationProvider;
use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Authentication\Exception\DisabledException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Password\NoOpPasswordEncoder;
use Firefly\Security\Password\PasswordEncoder;
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

it('normalizes an unknown user to BadCredentialsException and runs a verify to equalize timing (no enumeration)', function () {
    $encoder = new class implements PasswordEncoder
    {
        public int $matchCalls = 0;

        public function encode(string $rawPassword): string
        {
            return '{enc}'.$rawPassword;
        }

        public function matches(string $rawPassword, string $encodedPassword): bool
        {
            $this->matchCalls++;

            return $encodedPassword === '{enc}'.$rawPassword;
        }

        public function upgradeEncoding(string $encodedPassword): bool
        {
            return false;
        }
    };
    $provider = new DaoAuthenticationProvider(
        new InMemoryUserDetailsService([]), // empty store → every lookup is user-not-found
        $encoder,
    );

    expect($encoder->matchCalls)->toBe(0); // constructor used encode(), not matches()
    expect(fn () => $provider->authenticate(
        Authentication::unauthenticated('ghost', 'ghost', 'any-password'),
    ))->toThrow(BadCredentialsException::class);
    expect($encoder->matchCalls)->toBe(1); // a real verify ran on the not-found path — timing equalized
});
