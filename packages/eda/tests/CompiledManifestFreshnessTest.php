<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed manifests under packages/eda/cache/ (which production LOADS) must stay in lockstep
 * with packages/eda/src. Regenerate to a temp path with the REAL compiler and compare LOADED descriptor content
 * (not raw bytes) so var_export formatting can never false-fail.
 */
it('ships compiled manifests that are fresh against eda/src', function () {
    $src = ['Firefly\\Eda\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-eda-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-eda-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-eda-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-eda-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
