<?php

declare(strict_types=1);

use Firefly\Testing\Integration\IntegrationDsn;

/**
 * A CI lane must not go green by skipping everything. Every integration test skips when its variable is missing —
 * the right behaviour on a laptop — which means a lane whose DSN export broke, or whose service never came up, would
 * report a clean run of skips. CI sets FIREFLY_INTEGRATION_LANE; when it is set, this test FAILS unless the lane's
 * variables are present, its PHP extension is loaded and its server answers. Without the variable it skips, so a
 * local `composer test:integration` is unaffected.
 */
it('finds the backends of the declared CI lane configured and reachable', function () {
    $lane = (string) IntegrationDsn::value(IntegrationDsn::LANE);

    if ($lane === 'brokers') {
        foreach ([IntegrationDsn::RABBITMQ, IntegrationDsn::KAFKA, IntegrationDsn::REDIS] as $variable) {
            expect(IntegrationDsn::missing($variable))->toBeFalse("the brokers lane runs without {$variable}");
        }
        expect(extension_loaded('rdkafka'))->toBeTrue('the brokers lane runs without ext-rdkafka');

        $rabbit = IntegrationDsn::rabbitmq();
        $kafka = explode(':', (string) IntegrationDsn::value(IntegrationDsn::KAFKA));
        $redis = parse_url((string) IntegrationDsn::value(IntegrationDsn::REDIS));
        $endpoints = [
            'rabbitmq' => [$rabbit['host'], $rabbit['port']],
            'kafka' => [$kafka[0], (int) ($kafka[1] ?? 9092)],
            'redis' => [is_array($redis) ? (string) ($redis['host'] ?? '127.0.0.1') : '127.0.0.1', is_array($redis) ? (int) ($redis['port'] ?? 6379) : 6379],
        ];
        foreach ($endpoints as $name => [$host, $port]) {
            $socket = @fsockopen($host, $port, $errno, $error, 5.0);
            expect($socket)->not->toBeFalse("{$name} at {$host}:{$port} is unreachable: {$error}");
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        return;
    }

    expect(IntegrationDsn::DATABASES)->toHaveKey($lane);
    expect(IntegrationDsn::skipReason($lane))->toBeNull();
    expect(IntegrationDsn::connect($lane, 'lane-sanity')->selectOne('select 1 as one'))->not->toBeNull();
})->skip(IntegrationDsn::missing(IntegrationDsn::LANE), 'FIREFLY_INTEGRATION_LANE is only set by the CI integration jobs.')->group('integration');
