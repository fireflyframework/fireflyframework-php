<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Tests\Support\GatedRoutes503TestCase;

uses(GatedRoutes503TestCase::class);

it('answers the configured disabled status', function (): void {
    /** @var GatedRoutes503TestCase $this */
    $this->postJson('/beta', [])->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
});
