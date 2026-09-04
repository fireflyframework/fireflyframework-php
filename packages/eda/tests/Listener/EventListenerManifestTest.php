<?php

declare(strict_types=1);

use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Listener\EventListenerManifestCompiler;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

it('round-trips descriptors through compile → require → load', function () {
    $descriptors = [
        new EventListenerDescriptor('App\\Listeners\\A', 'onUser', ['user.*'], 0),
        new EventListenerDescriptor('App\\Listeners\\B', 'onOrder', ['order.created', 'order.paid'], 3, ['orders']),
    ];

    $path = sys_get_temp_dir().'/firefly-eda-listeners-'.bin2hex(random_bytes(6)).'.php';
    try {
        (new EventListenerManifestCompiler)->write($descriptors, $path);
        $manifest = EventListenerManifest::load($path);

        expect($manifest->all())->toHaveCount(2)
            ->and($manifest->all()[1]->patterns)->toBe(['order.created', 'order.paid'])
            ->and($manifest->all()[1]->order)->toBe(3)
            ->and($manifest->all()[1]->destinations)->toBe(['orders'])
            ->and($manifest->all()[0]->destinations)->toBe([]);
    } finally {
        @unlink($path);
    }
});

it('throws a ConfigurationException when the manifest file is missing', function () {
    EventListenerManifest::load('/no/such/manifest.php');
})->throws(ConfigurationException::class);

/**
 * FORWARD-COMPATIBILITY OF THE ARTIFACT. `destinations` was added to the compiled row after apps were already
 * shipping manifests emitted by an older firefly/cli. Those files are still on disk, and an app that upgrades
 * firefly/eda without re-running `firefly:cache` must keep booting rather than fataling on a missing array key.
 * A legacy row therefore means "declared no destinations", which TopicSubscriptionResolver reads as "cannot
 * narrow safely" and answers with the catch-all — conservative, never a silent subscription gap.
 */
it('loads a legacy manifest row that predates the destinations key', function () {
    $path = sys_get_temp_dir().'/firefly-eda-legacy-'.bin2hex(random_bytes(6)).'.php';
    try {
        file_put_contents($path, "<?php\n\nreturn [['class' => 'App\\\\Listeners\\\\Legacy', 'method' => 'on', 'patterns' => ['user.*'], 'order' => 7]];\n");

        $listener = EventListenerManifest::load($path)->all()[0];

        expect($listener->class)->toBe('App\\Listeners\\Legacy')
            ->and($listener->order)->toBe(7)
            ->and($listener->destinations)->toBe([]);
    } finally {
        @unlink($path);
    }
});
