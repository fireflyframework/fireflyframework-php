<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\AutoConfigure\Tests\CompileFixtures\CompileSampleConfig;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

$psr4 = ['Firefly\\AutoConfigure\\Tests\\CompileFixtures\\' => __DIR__.'/../CompileFixtures'];

it('scans a PSR-4 map into in-memory Component + Context manifests', function () use ($psr4) {
    [$components, $context] = (new AutoConfigManifestCompiler)->scan($psr4);

    expect($components)->toBeInstanceOf(ComponentManifest::class)
        ->and($context)->toBeInstanceOf(ContextManifest::class)
        ->and(array_map(fn ($c) => $c->class, $components->components))->toContain(CompileSampleConfig::class)
        ->and($context->forClass(CompileSampleConfig::class))->not->toBeNull();
});

it('writes both manifests to disk, reloadable via the M2/M4 load() paths', function () use ($psr4) {
    $componentPath = sys_get_temp_dir().'/fac-c-'.bin2hex(random_bytes(6)).'.php';
    $contextPath = sys_get_temp_dir().'/fac-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($psr4, $componentPath, $contextPath);

        $components = ComponentManifest::load($componentPath);
        $context = ContextManifest::load($contextPath);

        expect(array_map(fn ($c) => $c->class, $components->components))->toContain(CompileSampleConfig::class)
            ->and($context->forClass(CompileSampleConfig::class)?->beanConditionInstances('sample'))->toHaveCount(1);
    } finally {
        @unlink($componentPath);
        @unlink($contextPath);
    }
});
