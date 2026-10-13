<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the committed manifests under packages/callbacks/cache/ (which production LOADS) must stay in
 * lockstep with packages/callbacks/src, compared LOADED so var_export formatting can never false-fail.
 */
it('ships compiled auto-config manifests that are fresh against callbacks/src', function () {
    $src = ['Firefly\\Callbacks\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-callbacks-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-callbacks-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-callbacks-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-callbacks-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
