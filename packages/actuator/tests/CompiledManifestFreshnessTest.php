<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

it('ships compiled auto-config manifests that are fresh against actuator/src', function () {
    $src = ['Firefly\\Actuator\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-actuator-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-actuator-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-actuator-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-actuator-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
