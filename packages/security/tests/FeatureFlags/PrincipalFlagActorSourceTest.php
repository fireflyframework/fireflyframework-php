<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\FeatureFlags\PrincipalFlagActorSource;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('names the authenticated principal and nobody otherwise', function (): void {
    $anonymous = (new PrincipalFlagActorSource)->actor();
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', 'ada', [])));

    expect($anonymous)->toBeNull()
        ->and((new PrincipalFlagActorSource)->actor())->toBe('ada');
});
