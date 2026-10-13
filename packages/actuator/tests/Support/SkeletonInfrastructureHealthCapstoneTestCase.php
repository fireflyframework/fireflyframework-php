<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

/**
 * The actuator over HTTP with its health settings taken from skeleton/config/firefly.php itself, evaluated under
 * the environment variables healthEnvironment() names. It proves the reference wires the cache, queue and redis
 * gates to FIREFLY_HEALTH_{CACHE,QUEUE,REDIS}_ENABLED as live keys: a commented-out sample would leave the
 * variables read by nothing, and an operator who set one would get no indicator and no hint why.
 *
 * The variables are set only while the skeleton is evaluated and restored straight after, so nothing leaks into
 * the next test's process environment.
 */
abstract class SkeletonInfrastructureHealthCapstoneTestCase extends ActuatorCapstoneTestCase
{
    /** @return array<string, string> the environment variables set while the skeleton is evaluated */
    abstract protected function healthEnvironment(): array;

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        $health = $this->skeletonHealth();
        $health['show-details'] = 'always';

        return [
            ...parent::configOverrides(),
            'firefly.management.endpoint.health' => $health,
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ];
    }

    /** @return array<mixed> */
    private function skeletonHealth(): array
    {
        $saved = [];

        foreach ($this->healthEnvironment() as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }

        try {
            /** @var array{management: array{endpoint: array{health: array<mixed>}}} $reference */
            $reference = require dirname(__DIR__, 4).'/skeleton/config/firefly.php';

            return $reference['management']['endpoint']['health'];
        } finally {
            foreach ($saved as $name => [$server, $env, $process]) {
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }

                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }

                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }
}
