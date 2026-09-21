<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * DRIFT-GUARD: the two committed auto-config manifests under packages/security-oauth2-client/cache/ (which
 * production LOADS) must stay in lockstep with the package's src. Regenerated to a temp path with the REAL
 * compiler and compared as LOADED content, so var_export formatting can never false-fail.
 */
it('ships compiled auto-config manifests that are fresh against security-oauth2-client/src', function () {
    $src = ['Firefly\\Security\\OAuth2\\Client\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-oauth2-client-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-oauth2-client-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-security-oauth2-client-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-security-oauth2-client-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
