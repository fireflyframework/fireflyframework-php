<?php

declare(strict_types=1);

use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Tests\Support\OpenApiDisabledCapstoneTestCase;
use Illuminate\Support\Facades\Artisan;

uses(OpenApiDisabledCapstoneTestCase::class);

it('mounts no route at all when the master gate is off', function () {
    /** @var OpenApiDisabledCapstoneTestCase $this */
    // Genuinely unrouted, not a guarded 404 inside an action: the router itself raises
    // NotFoundHttpException, which ProblemDetailsRenderer then renders as a proper 404 problem+json for a
    // JSON client rather than the 500 an unhandled framework exception would produce.
    $this->getJson('/openapi.json')->assertStatus(404);
    $this->getJson('/openapi')->assertStatus(404);
});

it('leaves the application routes untouched', function () {
    /** @var OpenApiDisabledCapstoneTestCase $this */
    $this->getJson('/api/orders/abc')->assertStatus(200);
});

it('still generates on demand with the master gate off', function () {
    /** @var OpenApiDisabledCapstoneTestCase $this */
    // The gate turns off the HTTP SURFACE, not the generator: a deployment that keeps the spec off its
    // public routes still has to be able to produce the document as a build artifact. That is why the gate
    // lives in OpenApiRouteRegistrar and not on the beans.
    expect($this->app()->make(OpenApiGenerator::class)->generate()['paths'])->toHaveKey('/api/orders')
        ->and(Artisan::call('firefly:openapi'))->toBe(0)
        ->and(trim(Artisan::output()))->toStartWith('{');
});
