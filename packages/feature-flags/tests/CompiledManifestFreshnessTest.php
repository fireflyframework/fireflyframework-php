<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the committed manifests under packages/feature-flags/cache/ (which production LOADS) must stay
 * in lockstep with packages/feature-flags/src. Regenerate with the real compiler and compare the LOADED
 * descriptors, never the bytes.
 */
it('ships compiled manifests that are fresh against feature-flags/src', function (): void {
    $src = ['Firefly\\FeatureFlags\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $components = sys_get_temp_dir().'/firefly-feature-flags-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $context = sys_get_temp_dir().'/firefly-feature-flags-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $components, $context);

        expect(ComponentManifest::load($components))->toEqual(ComponentManifest::load($cache.'/firefly-feature-flags-components.php'))
            ->and(ContextManifest::load($context))->toEqual(ContextManifest::load($cache.'/firefly-feature-flags-context.php'));
    } finally {
        @unlink($components);
        @unlink($context);
    }
});
