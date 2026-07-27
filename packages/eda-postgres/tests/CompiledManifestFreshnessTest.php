<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed manifests under packages/eda-postgres/cache/ (which production LOADS) must stay in
 * lockstep with packages/eda-postgres/src. We regenerate to a temp path with the REAL compiler and compare the
 * LOADED descriptor content (not raw bytes) so var_export/Pint formatting can never false-fail.
 */
it('ships compiled manifests that are fresh against eda-postgres/src', function () {
    $src = ['Firefly\\Eda\\Postgres\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';
    $c = sys_get_temp_dir().'/firefly-eda-postgres-c-'.bin2hex(random_bytes(6)).'.php';
    $x = sys_get_temp_dir().'/firefly-eda-postgres-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $c, $x);
        expect(ComponentManifest::load($c))->toEqual(ComponentManifest::load($cache.'/firefly-eda-postgres-components.php'))
            ->and(ContextManifest::load($x))->toEqual(ContextManifest::load($cache.'/firefly-eda-postgres-context.php'));
    } finally {
        @unlink($c);
        @unlink($x);
    }
});
