<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Server\FlagdSyncRouteRegistrar;
use Firefly\FeatureFlags\Tests\Support\CustomPathSyncServerTestCase;

uses(CustomPathSyncServerTestCase::class);

it('serves the configured path under the stable route name', function (): void {
    /** @var CustomPathSyncServerTestCase $this */
    $this->get('/internal/flags.json', ['Authorization' => 'Bearer s3cret'])->assertOk();
    $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret'])->assertNotFound();
    expect($this->app()->make('router')->getRoutes()->getByName(FlagdSyncRouteRegistrar::ROUTE_NAME)?->uri())->toBe('internal/flags.json');
});
