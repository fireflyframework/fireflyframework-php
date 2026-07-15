<?php

declare(strict_types=1);

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scanner\ManifestCompiler;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;
use Firefly\Container\Tests\Fixtures\LazyBeanConfig;
use Firefly\Container\Tests\Fixtures\LazyWidget;

it('compiles a scan to a cached PHP file and loads it back without reflection', function () {
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ManifestCompiler)->write($components, $path);

    expect(file_exists($path))->toBeTrue();
    // The cached artifact is a plain array literal — no class references executed at load.
    expect(require $path)->toBeArray();

    $manifest = ComponentManifest::load($path);
    $classes = array_map(static fn ($d) => $d->class, $manifest->components);

    expect($manifest)->toBeInstanceOf(ComponentManifest::class)
        ->and($classes)->toContain(EnglishGreeter::class)
        ->and(count($manifest->components))->toBe(count($components));

    unlink($path);
});

it('carries #[Lazy] through compile→load without reflecting at load time', function () {
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ManifestCompiler)->write($components, $path);

    $manifest = ComponentManifest::load($path);

    $byClass = [];
    foreach ($manifest->components as $d) {
        $byClass[$d->class] = $d;
    }

    expect($byClass[LazyWidget::class]->lazy)->toBeTrue()
        ->and($byClass[EnglishGreeter::class]->lazy)->toBeFalse();

    unlink($path);
});

it('carries #[Lazy] on a #[Bean] method through compile→load without reflecting at load time', function () {
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $path = sys_get_temp_dir().'/firefly-manifest-'.bin2hex(random_bytes(6)).'.php';
    (new ManifestCompiler)->write($components, $path);

    $manifest = ComponentManifest::load($path);

    $config = null;
    foreach ($manifest->components as $d) {
        if ($d->class === LazyBeanConfig::class) {
            $config = $d;
        }
    }

    expect($config)->not->toBeNull();

    if (! $config instanceof ComponentDescriptor) {
        throw new RuntimeException('LazyBeanConfig descriptor not found in manifest.');
    }

    $byMethod = [];
    foreach ($config->beans as $bean) {
        $byMethod[$bean->method] = $bean;
    }

    expect($byMethod['lazyGadget']->lazy)->toBeTrue()
        ->and($byMethod['eagerGadget']->lazy)->toBeFalse();

    unlink($path);
});
