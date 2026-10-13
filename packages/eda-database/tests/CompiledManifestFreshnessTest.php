<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed manifests under packages/eda-database/cache/ (which production LOADS) must stay in
 * lockstep with packages/eda-database/src — regenerated to a temp path with the REAL compiler and compared as loaded
 * descriptors (never raw bytes), the scheduling-postgres precedent.
 */
it('ships compiled manifests that are fresh against eda-database/src', function () {
    $src = ['Firefly\\Eda\\Database\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-eda-database-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-eda-database-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-eda-database-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-eda-database-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
