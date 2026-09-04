<?php

declare(strict_types=1);

namespace Firefly\Config\Binder;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Binds a config array onto a plain readonly DTO by matching constructor parameters to array keys.
 * Scalars are coerced; a parameter typed as another class is bound recursively from its sub-array.
 * The seam (ConfigBinder) lets a richer binder be swapped in without touching call sites.
 *
 * RELAXED BINDING. Key lookup is NOT a straight array_key_exists() on the PHP parameter name, and
 * for a concrete reason. A Laravel `config/*.php` file is written by hand, in whatever casing the
 * house style of the application prefers, and its values very often arrive from environment
 * variables that are SCREAMING_SNAKE by convention; a PHP constructor parameter, meanwhile, is
 * camelCase because PSR-12 says so. Matching only the exact name meant those two worlds simply
 * never met: `'daily_transfer_limit_minor' => 250000` in the config file bound NOTHING onto
 * `public int $dailyTransferLimitMinor`, and because an unmatched parameter with a constructor
 * default is not an error, the DTO came out holding the default. No exception, no log line, no
 * failing test — just a wrong limit in production. This repo's OWN BOOK shipped that exact example
 * (book/src/03-configuration.md: a `WalletProperties` with camelCase parameters over a
 * `config/wallet.php` with snake_case keys), which is how the defect was finally caught: the
 * documented, copy-pasteable worked example could not possibly have worked.
 *
 * So each parameter is now looked up under four spellings, in this fixed precedence order, exactly
 * mirroring Spring Boot's relaxed binding:
 *
 *   1. the exact parameter name        `dailyTransferLimitMinor`
 *   2. snake_case                      `daily_transfer_limit_minor`
 *   3. kebab-case                      `daily-transfer-limit-minor`
 *   4. SCREAMING_SNAKE_CASE            `DAILY_TRANSFER_LIMIT_MINOR`
 *
 * The order is total and data-independent — it depends only on the parameter name, never on the
 * iteration order of the config array — so binding is deterministic even when an array carries two
 * spellings of the same property at once. Duplicate spellings collapse (a parameter already named
 * in snake_case yields two candidates, not four), which also keeps the "tried these keys" text in
 * the missing-property exception honest.
 */
final class ReflectionConfigBinder implements ConfigBinder
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $config
     * @return T
     */
    public function bind(string $class, array $config): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $parameter) {
            $args[] = $this->resolveParameter($class, $parameter, $config);
        }

        try {
            return $reflection->newInstanceArgs($args);
        } catch (\TypeError $e) {
            throw new ConfigurationException(
                "Configuration for {$class} could not be bound: {$e->getMessage()}",
                previous: $e,
            );
        }
    }

    /**
     * @param  class-string  $class
     * @param  array<string,mixed>  $config
     */
    private function resolveParameter(string $class, ReflectionParameter $parameter, array $config): mixed
    {
        $name = $parameter->getName();
        $candidates = $this->candidateKeys($name);
        $value = null;

        foreach ($candidates as $candidate) {
            // A present-but-NULL key has always meant "not supplied" here — it is precisely what
            // the ubiquitous Laravel idiom `'port' => env('MAIL_PORT')` yields when the variable is
            // unset, and Config::required() reads it the same way. It therefore does not stop the
            // search: a null under the exact name must not mask a real value written in snake_case,
            // or an application would be punished for leaving an unused env() line in place.
            if (array_key_exists($candidate, $config) && $config[$candidate] !== null) {
                $value = $config[$candidate];
                break;
            }
        }

        if ($value === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($parameter->allowsNull()) {
                return null;
            }

            throw new ConfigurationException(sprintf(
                'Missing required configuration property [%s] for %s. Tried these keys: %s.',
                $name,
                $class,
                implode(', ', $candidates),
            ));
        }

        $type = $parameter->getType();
        if (! $type instanceof ReflectionNamedType) {
            return $value;
        }

        return $this->coerce($type, $value);
    }

    /**
     * Every config-array key that may supply $name, most specific first (see the class docblock for
     * the precedence rationale).
     *
     * The camelCase -> snake_case step is two passes rather than the usual one-liner
     * `preg_replace('/(?<!^)[A-Z]/', '_$0', $name)`, because that one-liner explodes acronyms: it
     * turns $apiURL into `api_u_r_l` and $HTTPProxyHost into `_h_t_t_p_proxy_host`, neither of
     * which anybody would ever type into a config file, so the relaxed spelling would be useless
     * for exactly the parameters most likely to carry one (URL, HTTP, API, DSN, TTL). The first
     * pattern breaks a lower-or-digit -> upper boundary (`apiURL` -> `api_URL`); the second breaks
     * an acronym that runs into a following word (`HTTPProxy` -> `HTTP_Proxy`). Lower-casing the
     * result then yields `api_url` and `http_proxy_host`.
     *
     * @return non-empty-list<string>
     */
    private function candidateKeys(string $name): array
    {
        $snake = strtolower((string) preg_replace(
            ['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'],
            '$1_$2',
            $name,
        ));

        /** @var non-empty-list<string> $candidates */
        $candidates = array_values(array_unique([
            $name,                            // exact
            $snake,                           // snake_case
            str_replace('_', '-', $snake),    // kebab-case
            strtoupper($snake),               // SCREAMING_SNAKE_CASE
        ]));

        return $candidates;
    }

    private function coerce(ReflectionNamedType $type, mixed $value): mixed
    {
        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'int' => is_numeric($value) ? (int) $value : $value,
                'float' => is_numeric($value) ? (float) $value : $value,
                'bool' => is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'string' => is_scalar($value) ? (string) $value : $value,
                default => $value, // array, mixed, etc.
            };
        }

        // A nested DTO: recurse when we have a sub-array to bind.
        $nested = $type->getName();
        if (is_array($value) && class_exists($nested)) {
            /** @var class-string $nested */
            /** @var array<string,mixed> $value */
            return $this->bind($nested, $value);
        }

        return $value;
    }
}
