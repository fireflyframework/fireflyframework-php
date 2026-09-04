<?php

declare(strict_types=1);

namespace Firefly\Config\Profile;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;

/**
 * Resolves the active profiles: FIREFLY_PROFILES_ACTIVE (comma-separated) takes precedence;
 * otherwise the single Laravel APP_ENV; otherwise the implicit "default" profile.
 *
 * WHY THIS IS NOT `getenv()`. It used to be — two bare getenv() calls — and that made profiles
 * collapse to ['default'] in the two situations where they matter most:
 *
 *  - Under orchestra/testbench. Testbench builds the application and sets its environment on the
 *    CONFIG REPOSITORY; it never calls putenv(), never writes $_ENV or $_SERVER, and never loads a
 *    .env file. getenv('APP_ENV') is therefore literally false in every LaraFly test suite, so a
 *    developer writing a test to prove that their #[Profile('test')] bean is registered watched it
 *    silently not be — with no error to explain why.
 *  - Under `php artisan config:cache`. Laravel's LoadEnvironmentVariables bootstrapper returns
 *    early when the configuration is cached, so .env is never parsed and getenv() sees nothing —
 *    while the cached repository holds the correct app.env the whole time. Profiles switched
 *    themselves off in production the moment an application followed the deployment guide.
 *
 * The fix is to consult, per setting, the three places Laravel itself would look, in this order:
 *
 *   1. Illuminate\Support\Env — the same reader behind Laravel's env() helper. It sees $_ENV and
 *      $_SERVER as well as putenv() values, so PHPUnit <env> entries, Docker --env, php-fpm env[]
 *      and a parsed .env all resolve here. A REAL environment variable is the most specific signal
 *      available, so it is checked first and beats a cached config value — deliberately, since
 *      overriding a baked artifact per process is the whole point of an env var.
 *   2. The config repository (firefly.profiles.active, app.env) — the cached-config and testbench
 *      answer. Injected explicitly where a caller has one; otherwise taken from the container's
 *      'config' binding, so the zero-argument `new ProfileResolver` call sites that already exist
 *      across the framework keep working and start seeing cached configuration for free.
 *   3. Raw getenv() — kept only as a last resort, for a process that manipulated the environment
 *      through putenv() after Env's repository was already built, or that runs with no Laravel
 *      application at all.
 *
 * A blank or non-scalar value at any level is treated as absent and the search continues, so an
 * empty `FIREFLY_PROFILES_ACTIVE=` in a .env cannot blank out a perfectly good APP_ENV.
 */
final class ProfileResolver
{
    /**
     * @param  Repository|null  $repository  the application's config repository; when null it is
     *                                       taken from the container's 'config' binding if there is one
     */
    public function __construct(private readonly ?Repository $repository = null) {}

    public function resolve(): Profiles
    {
        $explicit = $this->setting('FIREFLY_PROFILES_ACTIVE', 'firefly.profiles.active');
        if ($explicit !== null) {
            $names = $this->split($explicit);
            if ($names !== []) {
                return new Profiles($names);
            }
        }

        $environment = $this->setting('APP_ENV', 'app.env');
        if ($environment !== null) {
            return new Profiles([$environment]);
        }

        return new Profiles(['default']);
    }

    /**
     * The first non-blank value for one logical setting, read through the three sources documented
     * on the class, or null when none of them supplies one.
     */
    private function setting(string $variable, string $key): ?string
    {
        $candidates = [
            Env::get($variable),
            $this->repository()?->get($key),
            getenv($variable),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Flattens whatever a source handed back into a trimmed, non-empty string, or null.
     *
     * A list is accepted because `firefly.profiles.active` reads far more naturally in a PHP config
     * file as `['prod', 'eu']` than as the string 'prod,eu'; it is joined with a comma so that both
     * spellings converge on the same split() below. getenv() returns false when unset, and a config
     * key can hold anything at all, so every other shape is rejected rather than stringified into
     * nonsense like "Array" or "1".
     */
    private function normalize(mixed $value): ?string
    {
        if (is_array($value)) {
            $parts = array_filter($value, static fn (mixed $part): bool => is_string($part) || is_int($part) || is_float($part));
            $value = implode(',', array_map(static fn (string|int|float $part): string => (string) $part, $parts));
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function repository(): ?Repository
    {
        if ($this->repository !== null) {
            return $this->repository;
        }

        // Container::getInstance() materializes an empty container when the process never built an
        // application, which is why this is guarded by bound() rather than a bare get(): no Laravel
        // app simply means no repository, not an exception.
        $container = Container::getInstance();
        if (! $container->bound('config')) {
            return null;
        }

        $repository = $container->get('config');

        return $repository instanceof Repository ? $repository : null;
    }

    /**
     * @return list<string>
     */
    private function split(string $raw): array
    {
        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
