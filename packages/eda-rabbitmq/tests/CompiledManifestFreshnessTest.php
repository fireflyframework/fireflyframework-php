<?php

declare(strict_types=1);

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Scanner\ContextManifest;

it('ships compiled manifests that are fresh against eda-rabbitmq/src', function () {
    $src = ['Firefly\\Eda\\Rabbitmq\\' => dirname(__DIR__).'/src'];
    $cache = dirname(__DIR__).'/cache';
    $c = sys_get_temp_dir().'/firefly-eda-rabbitmq-c-'.bin2hex(random_bytes(6)).'.php';
    $x = sys_get_temp_dir().'/firefly-eda-rabbitmq-x-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new AutoConfigManifestCompiler)->write($src, $c, $x);
        expect(ComponentManifest::load($c))->toEqual(ComponentManifest::load($cache.'/firefly-eda-rabbitmq-components.php'))
            ->and(ContextManifest::load($x))->toEqual(ContextManifest::load($cache.'/firefly-eda-rabbitmq-context.php'));
    } finally {
        @unlink($c);
        @unlink($x);
    }
});
