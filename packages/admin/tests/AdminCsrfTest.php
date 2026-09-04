<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

uses(AdminCapstoneTestCase::class);

/**
 * The dashboard's write surfaces must not be forgeable from another site.
 *
 * THIS IS ASSERTED STRUCTURALLY, ON PURPOSE. Laravel's CSRF middleware returns early when
 * `runningUnitTests()` is true, so a feature test posting without a token gets a 302 whether the protection
 * is there or not — which is exactly how the hole survived being written: the forms carry `@csrf`, the tests
 * passed, and a real `curl -X POST` with no token changed the log level. Checking that the middleware is
 * ATTACHED is the assertion a test process can actually make; the behaviour was verified over real HTTP
 * (tokenless → 419, token+session → 302 and the write lands).
 */
it('mounts every dashboard route behind session and CSRF middleware', function () {
    /** @var AdminCapstoneTestCase $this */
    $admin = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'firefly')) {
            $admin[$route->uri()] = $route->gatherMiddleware();
        }
    }

    expect($admin)->not->toBeEmpty();

    foreach ($admin as $middleware) {
        // The session cookie has to survive the round trip too, or the token can never match on the way back.
        expect($middleware)->toContain(StartSession::class)
            ->toContain(ValidateCsrfToken::class)
            ->toContain(EncryptCookies::class);
    }
});

it('names the middleware classes rather than the web group', function () {
    /** @var AdminCapstoneTestCase $this */
    $route = null;
    foreach (Route::getRoutes()->getRoutes() as $candidate) {
        if ($candidate->uri() === 'firefly') {
            $route = $candidate;
        }
    }

    // Naming the group and guarding on `hasMiddlewareGroup('web')` attached NOTHING: this registrar runs
    // inside the framework's boot pipeline, before the application's RouteServiceProvider defines that
    // group, so the guard was false at registration time and produced an empty list — a fix that looked
    // applied and was not. The classes need no group and no ordering assumption.
    expect($route?->gatherMiddleware() ?? [])->not->toContain('web');
});
