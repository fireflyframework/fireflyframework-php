<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed auto-config manifests under packages/cqrs/cache/ (which production LOADS) must stay
 * in lockstep with packages/cqrs/src. Regenerate to a temp path with the REAL compiler and compare LOADED content,
 * not raw bytes, so var_export formatting can never false-fail.
 */
it('ships compiled auto-config manifests that are fresh against cqrs/src', function () {
    $src = ['Firefly\\Cqrs\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-cqrs-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-cqrs-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-cqrs-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-cqrs-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
