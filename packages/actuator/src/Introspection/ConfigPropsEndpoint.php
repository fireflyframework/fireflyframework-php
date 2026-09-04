<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

use BackedEnum;
use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Scan\AppScan;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * Lists every #[ConfigProperties] DTO the application bound, with its prefix and the values it actually
 * RESOLVED — Spring Boot Actuator's /actuator/configprops.
 *
 * WHY RESOLVED VALUES AND NOT THE CONFIG SUBTREE. /env already renders `firefly.*` as written. What it cannot
 * answer is the question that actually costs people afternoons: the config file says
 * `daily_transfer_limit_minor => 250000`, the DTO parameter is `$dailyTransferLimitMinor`, and the DTO is
 * holding its default — did relaxed binding match, or did it silently fall through? Reading the BOUND INSTANCE
 * back out of the container answers that directly, because it shows the value the application will actually
 * use, after relaxed binding, scalar coercion, nested-DTO construction and constructor defaults have all had
 * their say. A row whose `properties` disagree with the corresponding /env subtree IS the bug report.
 *
 * WHERE THE MANIFEST COMES FROM. The framework never binds ConfigPropertiesManifest into the container: it is
 * constructed by FireflyAutoConfigureServiceProvider and handed straight to FlushDefinitionsPass, which passes
 * it to ConfigRegistrar and drops it. So this endpoint resolves its own, following the SAME cached-then-scanned
 * convention AppScan documents for every Category-B manifest — compiled `config-properties.php` when
 * firefly:cache has run (production, zero reflection), an in-process scan of `firefly.scan.paths` otherwise
 * (development), an empty manifest when neither is configured. A container-bound manifest still wins if one is
 * ever present, so a future pass that binds it (or a test that supplies one) is honoured rather than ignored.
 * The manifest is memoized for the life of this singleton — the DTO LIST is fixed at deploy time, so re-reading
 * it per request would buy nothing; the VALUES are re-read on every request, so a rebound DTO shows through.
 *
 * WHY THE SCAN IS DEFERRED TO handle(). Resolving the manifest in the constructor would run a reflective
 * directory scan during EagerSingletonsPass on every uncached boot — including boots that never serve
 * /configprops, which is most of them, since the endpoint is not in the default exposure list. Deferring it to
 * the first request keeps that cost on the caller who asked for it, and is why this component needs no #[Lazy].
 *
 * MASKING. Resolved values run through the shared SensitiveValueMasker — the identical rule /env enforces,
 * including its array-valued-secret fix (see that class): a DTO property named `$apiToken` or `$signingKeys`
 * is masked whether it holds a string or a whole keyring.
 *
 * Return type is narrowed to the non-nullable EndpointResponse (the same covariant-narrowing idiom
 * BeansEndpoint/EnvEndpoint use): /configprops has no sub-resource concept to 404 on.
 */
#[Component]
final class ConfigPropsEndpoint implements ActuatorEndpoint
{
    /**
     * Objects nested more deeply than this render as their class name instead of being descended into. A
     * #[ConfigProperties] tree is built by ReflectionConfigBinder from a config array, so it is finite and
     * acyclic by construction — but nothing stops a DTO from holding a hand-built object with a back-reference,
     * and an introspection endpoint that can be made to recurse forever is a denial-of-service, not a feature.
     */
    private const int MAX_DEPTH = 8;

    private ?ConfigPropertiesManifest $manifest = null;

    public function __construct(private readonly Container $container) {}

    public function endpointId(): string
    {
        return 'configprops';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function handle(EndpointRequest $request): EndpointResponse
    {
        $beans = [];
        foreach ($this->manifest()->properties as $descriptor) {
            $beans[$descriptor->class] = $this->describe($descriptor->class, $descriptor->prefix, $descriptor->profiles);
        }

        // Keyed by class and sorted by it, rather than a list in manifest order. Keyed because that is the
        // shape Spring's own /configprops uses (`contexts.<ctx>.beans.<name>`) and the one a dashboard wants
        // for lookup; sorted because the fallback manifest comes from a recursive DIRECTORY WALK, whose order
        // depends on the filesystem — an unsorted payload would shuffle between two machines rendering the
        // same application and make a dashboard diff meaningless. Note that PHP encodes an EMPTY map as `[]`,
        // not `{}`; that is the same well-known quirk /loggers already lives with for its `loggers` map, and
        // it is left alone here rather than special-cased into a stdClass, so the endpoint keeps one type.
        ksort($beans);

        return EndpointResponse::json(['beans' => $beans]);
    }

    /**
     * One row per descriptor, with a FIXED key set — `bound` and `error` are always present, never omitted on
     * the happy path, so a dashboard can render one column layout instead of probing for optional keys.
     *
     * A DTO that is not bound is reported rather than dropped. Absence has exactly one cause worth surfacing:
     * ConfigRegistrar skipped it because its #[Profile] requirement does not match the active profiles, and
     * "declared, gated off under this profile" is precisely what an operator staring at a missing setting needs
     * to see. `profiles` on the same row is the explanation.
     *
     * @param  list<string>  $profiles
     * @return array{class: string, prefix: string, profiles: list<string>, bound: bool, properties: array<string, mixed>, error: string|null}
     */
    private function describe(string $class, string $prefix, array $profiles): array
    {
        $row = ['class' => $class, 'prefix' => $prefix, 'profiles' => $profiles, 'bound' => false, 'properties' => [], 'error' => null];

        if (! $this->container->bound($class)) {
            return $row;
        }

        try {
            $instance = $this->container->get($class);
        } catch (Throwable $e) {
            // A DTO whose required property is missing from config throws out of ConfigRegistrar's binding
            // closure the first time anything resolves it. Reporting that per row — instead of letting it
            // escape and turn the whole endpoint into a problem+json 500 — is the point of an introspection
            // endpoint: one unbindable DTO must not hide the twenty that bound correctly, and its message is
            // the most useful thing on the page.
            $row['error'] = $e::class.': '.$e->getMessage();

            return $row;
        }

        if (! is_object($instance)) {
            $row['error'] = 'Container returned a '.get_debug_type($instance).' for this class-string binding.';

            return $row;
        }

        $row['bound'] = true;
        $row['properties'] = SensitiveValueMasker::mask($this->properties($instance, 0));

        return $row;
    }

    /**
     * The instance's public, non-static properties — which is exactly the surface a #[ConfigProperties] DTO
     * has, since ReflectionConfigBinder builds it through constructor promotion.
     *
     * isInitialized() is checked because a DTO may declare a typed property the constructor does not assign;
     * reading one throws an Error, and an uninitialized property is honestly reported as null rather than
     * crashing the endpoint.
     *
     * @return array<string, mixed>
     */
    private function properties(object $instance, int $depth): array
    {
        $values = [];
        foreach ((new ReflectionClass($instance))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $values[$property->getName()] = $property->isInitialized($instance)
                ? $this->value($property->getValue($instance), $depth + 1)
                : null;
        }

        return $values;
    }

    /**
     * Renders one resolved value as something json_encode cannot choke on.
     *
     * Nested DTOs are descended into (ReflectionConfigBinder builds them recursively, so a `$database` property
     * really is another bound DTO and its values are just as interesting). Enums render as their backing value
     * — or their case name when they are pure — because that is the spelling the config file used. Anything
     * else non-scalar, a resource or a closure, renders as its type name: a config DTO should not be holding
     * one, and if it is, saying so is more useful than a JSON_THROW_ON_ERROR failure in the dispatch action.
     */
    private function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                return 'array';
            }

            $mapped = [];
            foreach ($value as $key => $item) {
                $mapped[$key] = $this->value($item, $depth + 1);
            }

            return $mapped;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_object($value)) {
            return $depth > self::MAX_DEPTH ? $value::class : $this->properties($value, $depth);
        }

        return is_scalar($value) || $value === null ? $value : get_debug_type($value);
    }

    private function manifest(): ConfigPropertiesManifest
    {
        return $this->manifest ??= $this->discoverManifest();
    }

    private function discoverManifest(): ConfigPropertiesManifest
    {
        if ($this->container->bound(ConfigPropertiesManifest::class)) {
            $bound = $this->boundManifest();
            if ($bound instanceof ConfigPropertiesManifest) {
                return $bound;
            }
        }

        $file = AppScan::cachedFile($this->container, AppScan::CONFIG_PROPERTIES);
        if ($file !== null) {
            return ConfigPropertiesManifest::load($file);
        }

        $paths = AppScan::paths($this->container);

        return new ConfigPropertiesManifest($paths === [] ? [] : (new ConfigPropertiesScanner)->scan($paths));
    }

    /**
     * Resolved through a dedicated method with an EXPLICIT `object` return type, rather than inline at the call
     * site: Larastan's container extension narrows `get(ConfigPropertiesManifest::class)` to exactly that class,
     * which makes the instanceof guard above read as dead code to PHPStan even though the container is a runtime
     * registry and nothing stops a host application from binding something else under that key. Declaring
     * `object` is the honest static type — the same idiom firefly/web's FilterChainRegistrar uses for the HTTP
     * kernel — so the check above is a real narrowing rather than a suppressed one.
     */
    private function boundManifest(): object
    {
        return $this->container->get(ConfigPropertiesManifest::class);
    }
}
