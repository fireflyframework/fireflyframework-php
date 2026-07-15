<?php

declare(strict_types=1);

use Firefly\Context\Condition\Attributes\ConditionalOnProfile;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextManifestCompiler;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\Fixtures\ConditionalConfigFixture;
use Firefly\Context\Tests\Fixtures\LifecycleFixtureWidget;

it('compiles a scan to a cached PHP file and loads it back without reflection', function () {
    $descriptors = (new ContextScanner)->scan([
        'Firefly\\Context\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-context-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ContextManifestCompiler)->write($descriptors, $path);

    expect(file_exists($path))->toBeTrue();
    // The cached artifact is a plain array literal — no class references executed at load.
    expect(require $path)->toBeArray();

    $manifest = ContextManifest::load($path);

    expect($manifest)->toBeInstanceOf(ContextManifest::class)
        ->and(count($manifest->descriptors))->toBe(count($descriptors));

    $lifecycle = $manifest->forClass(LifecycleFixtureWidget::class);
    if (! $lifecycle instanceof ContextDescriptor) {
        throw new RuntimeException('Expected a ContextDescriptor for LifecycleFixtureWidget.');
    }

    expect($lifecycle->postConstruct)->toBe(['init'])
        ->and($lifecycle->preDestroy)->toBe(['shutdown']);

    unlink($path);
});

it('carries a VARIADIC condition constructor (#[ConditionalOnProfile]) through compile→load and reconstructs it with new(), not reflection', function () {
    $descriptors = (new ContextScanner)->scan([
        'Firefly\\Context\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-context-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ContextManifestCompiler)->write($descriptors, $path);

    $manifest = ContextManifest::load($path);
    $descriptor = $manifest->forClass(ConditionalConfigFixture::class);
    if (! $descriptor instanceof ContextDescriptor) {
        throw new RuntimeException('Expected a ContextDescriptor for ConditionalConfigFixture.');
    }

    $profileConditions = $descriptor->beanConditionInstances('profileGated');
    expect($profileConditions)->toHaveCount(1);

    $profileCondition = $profileConditions[0];
    if (! $profileCondition instanceof ConditionalOnProfile) {
        throw new RuntimeException('Expected a ConditionalOnProfile instance.');
    }

    expect($profileCondition->profiles)->toBe(['dev', 'test']);

    unlink($path);
});

it('forClass() returns null for a class the manifest carries no descriptor for', function () {
    $manifest = new ContextManifest([]);

    expect($manifest->forClass('App\\Nowhere'))->toBeNull();
});
