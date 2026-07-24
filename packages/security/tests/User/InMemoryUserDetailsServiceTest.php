<?php

declare(strict_types=1);

use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\User\InMemoryUserDetailsService;
use Firefly\Security\User\User;

it('loads a known user with its authorities', function () {
    $service = new InMemoryUserDetailsService([
        new User('alice', '{bcrypt}$2y$10$abc', [new SimpleGrantedAuthority('ROLE_ADMIN')]),
    ]);

    $user = $service->loadUserByUsername('alice');

    expect($user->getUsername())->toBe('alice')
        ->and($user->isEnabled())->toBeTrue()
        ->and($user->isAccountNonLocked())->toBeTrue()
        ->and(array_map(fn ($a) => $a->getAuthority(), $user->getAuthorities()))->toBe(['ROLE_ADMIN']);
});

it('throws a 401 UsernameNotFoundException for an unknown user', function () {
    $service = new InMemoryUserDetailsService([]);

    $service->loadUserByUsername('nobody');
})->throws(UsernameNotFoundException::class);

it('builds from a config array', function () {
    $service = InMemoryUserDetailsService::fromConfig([
        'bob' => ['password' => '{noop}pw', 'authorities' => ['ROLE_USER'], 'enabled' => false],
    ]);

    $user = $service->loadUserByUsername('bob');
    expect($user->isEnabled())->toBeFalse()
        ->and($user->getPassword())->toBe('{noop}pw');
});
