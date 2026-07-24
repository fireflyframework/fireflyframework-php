<?php

declare(strict_types=1);

use Firefly\Security\Access\RoleHierarchy;

it('expands implied roles transitively', function () {
    $hierarchy = RoleHierarchy::fromRules(['ROLE_ADMIN > ROLE_STAFF', 'ROLE_STAFF > ROLE_USER']);

    expect($hierarchy->reachableAuthorities(['ROLE_ADMIN']))
        ->toEqualCanonicalizing(['ROLE_ADMIN', 'ROLE_STAFF', 'ROLE_USER']);
});

it('leaves unrelated authorities untouched', function () {
    $hierarchy = RoleHierarchy::fromRules(['ROLE_ADMIN > ROLE_USER']);

    expect($hierarchy->reachableAuthorities(['orders:read']))->toBe(['orders:read']);
});
