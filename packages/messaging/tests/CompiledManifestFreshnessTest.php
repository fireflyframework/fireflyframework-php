<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

/** DRIFT-GUARD: the committed packages/messaging/cache manifests must stay fresh against packages/messaging/src. */
it('ships compiled manifests that are fresh against messaging/src', function () {
    $src = ['Firefly\\Messaging\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';

    $freshComponents = sys_get_temp_dir().'/firefly-messaging-fresh-c-'.bin2hex(random_bytes(6)).'.php';
    $freshContext = sys_get_temp_dir().'/firefly-messaging-fresh-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $freshComponents, $freshContext);

        expect(ComponentManifest::load($freshComponents))->toEqual(ComponentManifest::load($cache.'/firefly-messaging-components.php'))
            ->and(ContextManifest::load($freshContext))->toEqual(ContextManifest::load($cache.'/firefly-messaging-context.php'));
    } finally {
        @unlink($freshComponents);
        @unlink($freshContext);
    }
});
