<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Closure;

/**
 * ProfileResolver reads FIREFLY_PROFILES_ACTIVE and APP_ENV from the environment BEFORE the configuration, so a
 * test that pins profiles through configuration runs with both absent ($_ENV, $_SERVER and putenv) and puts back
 * whatever was there afterwards.
 */
final class ProfileVariables
{
    private const array NAMES = ['FIREFLY_PROFILES_ACTIVE', 'APP_ENV'];

    public static function absent(Closure $test): void
    {
        $saved = [];
        foreach (self::NAMES as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        try {
            $test();
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if (is_string($process)) {
                    putenv($name.'='.$process);
                }
            }
        }
    }
}
