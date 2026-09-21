<?php

declare(strict_types=1);

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Security\ActingPrincipalMiddleware;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\Facades\Route;

uses(FireflyTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('establishes the principal for direct calls AND for every HTTP request, and restores it afterwards', function () {
    /** @var FireflyTestCase $this */
    Route::get('/acting-whoami', static fn (): array => [
        'name' => SecurityContextHolder::getAuthentication()?->getName(),
        'authorities' => SecurityContextHolder::getAuthentication()?->authorityStrings(),
    ]);

    $this->actingAsPrincipal('ada', ['ROLE_USER', 'orders:read'], ['id' => 7]);

    expect(SecurityContextHolder::getAuthentication()?->getName())->toBe('ada')
        ->and(SecurityContextHolder::getAuthentication()?->getPrincipal())->toBe(['id' => 7]);

    $this->getJson('/acting-whoami')->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER', 'orders:read']]);

    // Still acting after the request: the middleware restores what it found.
    expect(SecurityContextHolder::getAuthentication()?->getName())->toBe('ada');

    /** @var FoundationHttpKernel $kernel */
    $kernel = $this->app()->make(HttpKernelContract::class);
    expect($kernel->hasMiddleware(ActingPrincipalMiddleware::class))->toBeTrue()
        ->and($kernel->getGlobalMiddleware()[0])->toBe(ActingPrincipalMiddleware::class);

    // Acting twice swaps the principal rather than stacking middleware.
    $this->actingAsPrincipal('root', ['ROLE_ADMIN']);
    $this->getJson('/acting-whoami')->assertJson(['name' => 'root']);
    expect(array_count_values($kernel->getGlobalMiddleware())[ActingPrincipalMiddleware::class])->toBe(1);
});

it('defaults the principal to the name', function () {
    /** @var FireflyTestCase $this */
    $this->actingAsPrincipal('svc');

    expect(SecurityContextHolder::getAuthentication()?->getPrincipal())->toBe('svc')
        ->and(SecurityContextHolder::getAuthentication()?->getAuthorities())->toBe([]);
});
