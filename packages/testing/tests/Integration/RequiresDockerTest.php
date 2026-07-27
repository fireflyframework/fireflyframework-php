<?php

declare(strict_types=1);

use Firefly\Testing\Integration\RequiresDocker;

it('exposes a docker-availability probe', function () {
    expect(is_docker_available())->toBeBool();
});

it('maps a duck-typed container into flat firefly config', function () {
    $container = new class
    {
        public function getHost(): string
        {
            return '127.0.0.1';
        }

        public function getMappedPort(): int
        {
            return 55432;
        }
    };

    $config = fireflyConfigFor($container);

    expect($config['database.connections.testing.host'])->toBe('127.0.0.1')
        ->and($config['database.connections.testing.port'])->toBe(55432);
});

it('provides a docker-gated skip trait', function () {
    $obj = new class
    {
        use RequiresDocker;

        public function probe(): bool
        {
            // @phpstan-ignore function.alreadyNarrowedType (statically true here; the probe still proves the trait wires the method at runtime)
            return method_exists($this, 'skipUnlessDocker');
        }
    };

    expect($obj->probe())->toBeTrue();
});
