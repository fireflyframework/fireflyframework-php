<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed manifests under packages/validation/cache/ (which production LOADS, and which
 * the shipped ValidationServiceProvider points at) must stay in lockstep with packages/validation/src. If a
 * #[Configuration]/#[Bean]/#[ConditionalOn*] ever changes without re-running the compiler, CI must fail here
 * rather than silently shipping a stale manifest. We regenerate to a temp path with the REAL compiler and
 * compare the LOADED descriptor content (not raw bytes) so var_export formatting noise can never false-fail.
 */
it('ships compiled manifests that are fresh against validation/src', function () {
    $src = ['Firefly\\Validation\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-validation-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-validation-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        $committedComponents = ComponentManifest::load($cache.'/firefly-validation-components.php');
        $committedContext = ContextManifest::load($cache.'/firefly-validation-context.php');

        expect(ComponentManifest::load($freshComponents))->toEqual($committedComponents)
            ->and(ContextManifest::load($freshContext))->toEqual($committedContext);
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
