<?php

declare(strict_types=1);

use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerManifestCompiler;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

it('round-trips handlers + destinations through compile/require/load', function () {
    $handlers = [
        new HandlerDescriptor('App\CreateOrder', 'App\CreateOrderHandler', 'handle', HandlerKind::Command),
        new HandlerDescriptor('App\FindOrder', 'App\FindOrderHandler', 'handle', HandlerKind::Query),
    ];
    $destinations = ['App\OrderPlaced' => 'orders.events'];

    $path = sys_get_temp_dir().'/firefly-cqrs-handlers-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new HandlerManifestCompiler)->write($handlers, $destinations, $path);
        $manifest = HandlerManifest::load($path);

        expect($manifest->handlers())->toHaveCount(2)
            ->and($manifest->handlers()[0]->messageClass)->toBe('App\CreateOrder')
            ->and($manifest->handlers()[0]->kind)->toBe(HandlerKind::Command)
            ->and($manifest->handlers()[1]->kind)->toBe(HandlerKind::Query)
            ->and($manifest->destinations())->toBe(['App\OrderPlaced' => 'orders.events']);
    } finally {
        @unlink($path);
    }
});

it('load() fails loud on a missing manifest', function () {
    HandlerManifest::load(sys_get_temp_dir().'/does-not-exist-'.bin2hex(random_bytes(6)).'.php');
})->throws(ConfigurationException::class);

it('load() fails loud when the file does not return an array', function () {
    $path = sys_get_temp_dir().'/firefly-cqrs-bad-'.bin2hex(random_bytes(6)).'.php';
    file_put_contents($path, "<?php\n\nreturn 42;\n");

    try {
        HandlerManifest::load($path);
    } finally {
        @unlink($path);
    }
})->throws(ConfigurationException::class);
