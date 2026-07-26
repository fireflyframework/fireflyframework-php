<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Info\AppInfoContributor;
use Firefly\Actuator\Info\BuildInfoContributor;
use Firefly\Actuator\Info\InfoContributorRegistry;
use Firefly\Actuator\Info\InfoEndpoint;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

it('merges app and build contributors into the info payload', function () {
    $buildFile = sys_get_temp_dir().'/firefly-build-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($buildFile, json_encode(['version' => '1.2.3', 'time' => '2026-07-26T00:00:00Z']));

    $config = new Config(new Repository(['firefly' => ['management' => ['info' => [
        'app' => ['name' => 'Demo', 'version' => '9.9'],
        'build' => ['path' => $buildFile],
    ]]]]));

    $registry = new InfoContributorRegistry;
    $registry->register(new AppInfoContributor($config));
    $registry->register(new BuildInfoContributor($config));

    try {
        $response = (new InfoEndpoint($registry))->handle(new EndpointRequest('GET', []));

        expect($response->status)->toBe(200)
            ->and($response->body)->toBe([
                'app' => ['name' => 'Demo', 'version' => '9.9'],
                'build' => ['version' => '1.2.3', 'time' => '2026-07-26T00:00:00Z'],
            ]);
    } finally {
        @unlink($buildFile);
    }
});

it('omits build when no build file exists', function () {
    $config = new Config(new Repository(['firefly' => ['management' => ['info' => ['build' => ['path' => '/no/such/build.json']]]]]));

    expect((new BuildInfoContributor($config))->info())->toBe([]);
});
