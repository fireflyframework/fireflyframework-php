<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Messaging\Listener\MessageListenerDescriptor;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\Listener\MessageListenerManifestCompiler;

it('round-trips descriptors through compile → require → load', function () {
    $descriptors = [
        new MessageListenerDescriptor('App\\Consumers\\A', 'onOrder', 'orders', 'workers', 2, 0.25, 'orders.DLT'),
        new MessageListenerDescriptor('App\\Consumers\\B', 'onPayment', 'payments', null, 0, 0.0, null),
    ];

    $path = sys_get_temp_dir().'/firefly-messaging-listeners-'.bin2hex(random_bytes(6)).'.php';
    try {
        (new MessageListenerManifestCompiler)->write($descriptors, $path);
        $manifest = MessageListenerManifest::load($path);

        expect($manifest->all())->toHaveCount(2)
            ->and($manifest->all()[0]->retryDelay)->toBe(0.25)
            ->and($manifest->all()[0]->deadLetterTopic)->toBe('orders.DLT')
            ->and($manifest->all()[1]->group)->toBeNull()
            ->and($manifest->all()[1]->deadLetterTopic)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('throws a ConfigurationException when the manifest file is missing', function () {
    MessageListenerManifest::load('/no/such/manifest.php');
})->throws(ConfigurationException::class);
