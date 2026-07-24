<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Data\SecurityContextAuditorAware;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('returns the current principal name when authenticated', function () {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('alice', 'alice', [])));

    expect((new SecurityContextAuditorAware)->currentAuditor())->toBe('alice');
});

it('returns null when anonymous', function () {
    expect((new SecurityContextAuditorAware)->currentAuditor())->toBeNull();
});
