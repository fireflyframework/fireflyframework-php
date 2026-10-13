<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed manifests under packages/orchestration/cache/ (which production LOADS) must
 * stay in lockstep with packages/orchestration/src. Regenerated to a temp path with the REAL compiler and
 * compared as loaded descriptors, so var_export formatting can never false-fail.
 */
it('ships compiled manifests that are fresh against orchestration/src', function (): void {
    $src = ['Firefly\\Orchestration\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-orchestration-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-orchestration-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-orchestration-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-orchestration-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
