<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SimpleGrantedAuthority;

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
