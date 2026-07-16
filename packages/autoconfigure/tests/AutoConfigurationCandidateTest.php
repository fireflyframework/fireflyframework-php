<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfigurationCandidate;

it('is an immutable value object carrying a provider FQCN and its two compiled manifest paths', function () {
    $candidate = new AutoConfigurationCandidate(
        provider: 'App\\CacheAutoConfiguration',
        componentManifestPath: '/tmp/components.php',
        contextManifestPath: '/tmp/context.php',
    );

    expect($candidate->provider)->toBe('App\\CacheAutoConfiguration')
        ->and($candidate->componentManifestPath)->toBe('/tmp/components.php')
        ->and($candidate->contextManifestPath)->toBe('/tmp/context.php')
        ->and((new ReflectionClass($candidate))->isReadOnly())->toBeTrue();
});
