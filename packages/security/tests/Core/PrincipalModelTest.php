<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\User\User;

it('builds an authenticated principal that erases credentials without mutating', function () {
    $auth = Authentication::authenticated('alice', 'alice', [new SimpleGrantedAuthority('ROLE_USER')]);

    expect($auth->isAuthenticated())->toBeTrue()
        ->and($auth->getName())->toBe('alice')
        ->and($auth->authorityStrings())->toBe(['ROLE_USER']);

    $erased = $auth->eraseCredentials();
    expect($erased->getCredentials())->toBeNull()
        ->and($erased)->not->toBe($auth)
        ->and($erased->isAuthenticated())->toBeTrue();
});

it('erases the principal\'s own credential along with the token\'s when the principal is a CredentialsContainer', function () {
    $user = new User('ada', '{bcrypt}$2y$04$hash', [new SimpleGrantedAuthority('ROLE_USER')], enabled: true, accountNonLocked: false);
    $auth = Authentication::authenticated('ada', $user, $user->getAuthorities());

    $erased = $auth->eraseCredentials();
    /** @var User $principal */
    $principal = $erased->getPrincipal();

    // The copy: same username, authorities and account flags, no hash. The original: untouched — it is the
    // token a remember-me service signs its cookie with.
    expect($principal)->not->toBe($user)
        ->and($principal->getPassword())->toBe('')
        ->and($principal->getUsername())->toBe('ada')
        ->and($principal->getAuthorities())->toBe($user->getAuthorities())
        ->and($principal->isEnabled())->toBeTrue()
        ->and($principal->isAccountNonLocked())->toBeFalse()
        ->and($erased->getName())->toBe('ada')
        ->and($erased->isAuthenticated())->toBeTrue()
        ->and($user->getPassword())->toBe('{bcrypt}$2y$04$hash')
        ->and($auth->getPrincipal())->toBe($user);

    // A principal that is not a CredentialsContainer (a JWT subject) is carried through as it is.
    expect(Authentication::authenticated('svc', 'svc', [])->eraseCredentials()->getPrincipal())->toBe('svc');
});

it('exposes an unauthenticated token that carries credentials but no authorities', function () {
    $token = Authentication::unauthenticated('bob', 'bob', 'secret');

    expect($token->isAuthenticated())->toBeFalse()
        ->and($token->getCredentials())->toBe('secret')
        ->and($token->getAuthorities())->toBe([]);
});

it('returns an anonymous SecurityContext with no authentication', function () {
    $context = SecurityContext::anonymous();

    expect($context->getAuthentication())->toBeNull()
        ->and($context->isAuthenticated())->toBeFalse();
});
