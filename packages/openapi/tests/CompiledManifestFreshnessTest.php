<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/**
 * The shipped cache/ artifacts are what make this package work in a bare-skeleton boot with no app scan
 * configured, so they must never drift from src/. Adding a #[Bean] and forgetting to recompile would leave
 * that bean simply absent in production while every test that scans src/ in-process kept passing.
 */
it('ships compiled auto-config manifests that are fresh against openapi/src', function () {
    $src = ['Firefly\\OpenApi\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-openapi-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-openapi-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-openapi-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-openapi-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
