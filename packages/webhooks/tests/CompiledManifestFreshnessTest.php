<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the committed manifests under packages/webhooks/cache/ (which production LOADS) must stay in
 * lockstep with packages/webhooks/src. Regenerated to a temp path with the real compiler and compared LOADED, so
 * var_export formatting can never false-fail.
 */
it('ships compiled auto-config manifests that are fresh against webhooks/src', function () {
    $src = ['Firefly\\Webhooks\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-webhooks-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-webhooks-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-webhooks-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-webhooks-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
