<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Scheduling\Schedule\ScheduledManifestCompiler;

it('round-trips descriptors through the compiler and the require-loaded manifest', function () {
    $descriptors = [
        new ScheduledDescriptor(class: 'App\\Jobs\\Reconcile', method: 'run', cron: '0 3 * * *', lockName: 'App\\Jobs\\Reconcile::run', lockTtl: '30s'),
        new ScheduledDescriptor(class: 'App\\Jobs\\Refill', method: 'tick', fixedRate: '5m'),
    ];

    $path = sys_get_temp_dir().'/firefly-scheduling-manifest-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new ScheduledManifestCompiler)->write($descriptors, $path);
        $loaded = ScheduledManifest::load($path)->all();

        expect($loaded)->toHaveCount(2)
            ->and($loaded[0])->toBeInstanceOf(ScheduledDescriptor::class)
            ->and($loaded[0]->class)->toBe('App\\Jobs\\Reconcile')
            ->and($loaded[0]->cron)->toBe('0 3 * * *')
            ->and($loaded[0]->lockName)->toBe('App\\Jobs\\Reconcile::run')
            ->and($loaded[0]->lockTtl)->toBe('30s')
            ->and($loaded[0]->fixedRate)->toBeNull()
            ->and($loaded[1]->fixedRate)->toBe('5m')
            ->and($loaded[1]->lockName)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('toArray mirrors the descriptor shape key-for-key', function () {
    $descriptor = new ScheduledDescriptor(class: 'A', method: 'b', fixedDelay: '10s', initialDelay: '2s', zone: 'UTC');

    expect((new ScheduledManifestCompiler)->toArray([$descriptor]))->toBe([[
        'class' => 'A',
        'method' => 'b',
        'cron' => null,
        'fixedRate' => null,
        'fixedDelay' => '10s',
        'initialDelay' => '2s',
        'zone' => 'UTC',
        'lockName' => null,
        'lockTtl' => null,
    ]]);
});

it('throws when the manifest file is missing', function () {
    expect(fn () => ScheduledManifest::load('/no/such/scheduling-manifest.php'))->toThrow(ConfigurationException::class);
});
