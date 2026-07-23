<?php

declare(strict_types=1);

use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Listener\EventListenerManifestCompiler;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

it('round-trips descriptors through compile → require → load', function () {
    $descriptors = [
        new EventListenerDescriptor('App\\Listeners\\A', 'onUser', ['user.*'], 0),
        new EventListenerDescriptor('App\\Listeners\\B', 'onOrder', ['order.created', 'order.paid'], 3),
    ];

    $path = sys_get_temp_dir().'/firefly-eda-listeners-'.bin2hex(random_bytes(6)).'.php';
    try {
        (new EventListenerManifestCompiler)->write($descriptors, $path);
        $manifest = EventListenerManifest::load($path);

        expect($manifest->all())->toHaveCount(2)
            ->and($manifest->all()[1]->patterns)->toBe(['order.created', 'order.paid'])
            ->and($manifest->all()[1]->order)->toBe(3);
    } finally {
        @unlink($path);
    }
});

it('throws a ConfigurationException when the manifest file is missing', function () {
    EventListenerManifest::load('/no/such/manifest.php');
})->throws(ConfigurationException::class);
