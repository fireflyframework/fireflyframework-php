<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Event\FeatureFlagEvaluated;
use Firefly\FeatureFlags\Tests\Fixtures\GatedRoutes\BetaController;
use Firefly\FeatureFlags\Tests\Support\GatedRoutesTestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

uses(GatedRoutesTestCase::class);

it('answers a dark route before binding its body, without running the controller', function (): void {
    /** @var GatedRoutesTestCase $this */
    $this->postJson('/beta', ['not' => 'a payload'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

    expect(BetaController::$calls)->toBe(0);
});

it('serves the route once the flag is on', function (): void {
    /** @var GatedRoutesTestCase $this */
    $this->overrideFlags(['beta-api' => true, 'checkout-flow' => 'v2']);

    $this->postJson('/beta', ['sku' => 'A-1'])->assertOk()->assertJson(['sku' => 'A-1']);
});

it('binds a fallback route before selecting its fallback', function (): void {
    /** @var GatedRoutesTestCase $this */
    $this->postJson('/beta/fallback', ['not' => 'a payload'])->assertStatus(400);
    $this->postJson('/beta/fallback', ['sku' => 'A-1'])->assertOk()->assertJson(['fallback' => 'A-1']);

    expect(BetaController::$calls)->toBe(0);
});

it('gates on a variant and evaluates once per request', function (): void {
    /** @var GatedRoutesTestCase $this */
    /** @var list<FeatureFlagEvaluated> $evaluations */
    $evaluations = [];
    Event::listen(FeatureFlagEvaluated::class, function (FeatureFlagEvaluated $event) use (&$evaluations): void {
        $evaluations[] = $event;
    });

    $this->getJson('/beta/v2')->assertOk();
    $this->overrideFlags(['beta-api' => false, 'checkout-flow' => 'v1']);
    $this->getJson('/beta/v2')->assertStatus(404);

    expect(array_map(static fn (FeatureFlagEvaluated $event): string => $event->key, $evaluations))->toBe(['checkout-flow', 'checkout-flow'])
        ->and(BetaController::$calls)->toBe(1);
});

it('leaves an unannotated action alone', function (): void {
    /** @var GatedRoutesTestCase $this */
    $this->getJson('/beta/open')->assertOk()->assertJson(['open' => true]);
});

it('gates a route declared in a route file through the feature-flag alias', function (): void {
    /** @var GatedRoutesTestCase $this */
    Route::get('/legacy-report', static fn (): string => 'report')->middleware('feature-flag:beta-api');
    Route::get('/legacy-v2', static fn (): string => 'v2')->middleware('feature-flag:checkout-flow,v2');

    $this->get('/legacy-report')->assertStatus(404);
    $this->get('/legacy-v2')->assertOk()->assertSee('v2');
});

it('honors the route-file default for missing flags and variants', function (): void {
    /** @var GatedRoutesTestCase $this */
    Route::get('/missing-closed', static fn (): string => 'closed')->middleware('feature-flag:missing');
    Route::get('/missing-open', static fn (): string => 'open')->middleware('feature-flag:missing,,true');
    Route::get('/missing-variant-open', static fn (): string => 'open')->middleware('feature-flag:missing,v2,true');

    $this->get('/missing-closed')->assertStatus(404);
    $this->get('/missing-open')->assertOk()->assertSee('open');
    $this->get('/missing-variant-open')->assertOk()->assertSee('open');
});

it('registers the Blade directives with the view layer', function (): void {
    /** @var GatedRoutesTestCase $this */
    expect(trim(Blade::render("@featureflag('beta-api') yes @else no @endfeatureflag")))->toBe('no');
});
