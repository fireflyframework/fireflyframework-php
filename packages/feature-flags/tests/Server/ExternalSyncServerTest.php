<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Tests\Support\ExternalSyncServerTestCase;
use Firefly\FeatureFlags\Tests\Support\FixedProvider;
use OpenFeature\interfaces\provider\Provider;

uses(ExternalSyncServerTestCase::class);

it('does not mount the sync route for an application-provided OpenFeature provider', function (): void {
    /** @var ExternalSyncServerTestCase $this */
    $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret'])->assertNotFound();
    expect($this->app()->bound(FlagRegistry::class))->toBeFalse()
        ->and($this->fireflyContext()->get(Provider::class))->toBeInstanceOf(FixedProvider::class);
});
