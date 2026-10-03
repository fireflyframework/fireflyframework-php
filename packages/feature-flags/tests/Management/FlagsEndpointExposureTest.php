<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Tests\Support\ManagementEndpointTestCase;

class FeatureFlagsUnexposedEndpointTestCase extends ManagementEndpointTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.management.endpoints.web.exposure.include' => 'health'];
    }
}

uses(FeatureFlagsUnexposedEndpointTestCase::class);

it('does not route flags without explicit exposure', function (): void {
    /** @var FeatureFlagsUnexposedEndpointTestCase $this */
    $this->getJson('/actuator/flags')->assertNotFound();
    $this->postJson('/actuator/flags/2024', ['action' => 'evaluate'])->assertNotFound();
});
