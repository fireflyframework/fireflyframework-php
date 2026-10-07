<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Config\Config;
use Firefly\FeatureFlags\Settings\DurationReader;
use Firefly\FeatureFlags\Settings\FileSourceSettings;
use Firefly\FeatureFlags\Settings\HttpSourceSettings;
use Firefly\FeatureFlags\Settings\ServerSettings;
use Firefly\FeatureFlags\Settings\StoreSourceSettings;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * firefly.feature-flags.*, read once. The `flags` and `evaluators` maps are kept verbatim: their keys are flag
 * and evaluator names, never settings. A malformed value refuses the boot here, naming the key. Null is unset:
 * every leaf set to null (`env('X')` with X unset) takes its default.
 *
 * Durations (DurationReader) take the framework grammar or a number of seconds; a negative one is refused. Zero
 * is allowed only where it is cheap: a file or store `refresh-interval` of 0 re-checks that source on every
 * registry refresh, that is on every request (a stat, or one MAX(id) query). Both http durations must be above
 * zero: an http `refresh-interval` of 0 would send the remote server one conditional GET per request with no
 * stampede lock, and Laravel's HTTP client reads a `timeout` of 0 as no timeout at all.
 */
final readonly class FeatureFlagsSettings
{
    /** @var list<int> */
    public const array DISABLED_STATUSES = [404, 403, 503];

    /** @var list<string> */
    public const array STORE_DRIVERS = ['database', 'memory'];

    /**
     * @param  array<array-key, mixed>  $flags
     * @param  array<array-key, mixed>  $evaluators
     */
    public function __construct(
        public bool $enabled,
        public array $flags,
        public array $evaluators,
        public FileSourceSettings $file,
        public HttpSourceSettings $http,
        public StoreSourceSettings $store,
        public string $tenantAttribute,
        public int $disabledStatus,
        public bool $publishEvaluations,
        public bool $writes,
        public ServerSettings $server,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $durations = new DurationReader($config);

        $file = new FileSourceSettings(
            enabled: $config->bool('firefly.feature-flags.sources.file.enabled', false),
            path: $config->string('firefly.feature-flags.sources.file.path', ''),
            refreshInterval: $durations->get('firefly.feature-flags.sources.file.refresh-interval', 5.0),
        );
        if ($file->enabled && trim($file->path) === '') {
            throw new ConfigurationException('firefly.feature-flags.sources.file.enabled is on but firefly.feature-flags.sources.file.path is empty: name the flagd document (.json, .yaml or .yml) to watch.');
        }

        $http = new HttpSourceSettings(
            enabled: $config->bool('firefly.feature-flags.sources.http.enabled', false),
            url: $config->string('firefly.feature-flags.sources.http.url', ''),
            token: $config->string('firefly.feature-flags.sources.http.token', ''),
            refreshInterval: $durations->get('firefly.feature-flags.sources.http.refresh-interval', 30.0, refuseZeroBecause: 'zero would send the remote server one conditional GET per request, with no stampede lock'),
            timeout: $durations->get('firefly.feature-flags.sources.http.timeout', 2.0, refuseZeroBecause: "Laravel's HTTP client reads zero as no timeout at all"),
        );
        if ($http->enabled && trim($http->url) === '') {
            throw new ConfigurationException('firefly.feature-flags.sources.http.enabled is on but firefly.feature-flags.sources.http.url is empty: name the sync endpoint to poll.');
        }

        $driver = $config->string('firefly.feature-flags.sources.store.driver', 'database');
        if (! in_array($driver, self::STORE_DRIVERS, true)) {
            throw new ConfigurationException("firefly.feature-flags.sources.store.driver must be 'database' or 'memory', got [{$driver}].");
        }
        $connection = $config->get('firefly.feature-flags.sources.store.connection');
        if ($connection !== null && ! is_string($connection)) {
            throw new ConfigurationException('firefly.feature-flags.sources.store.connection must name a database connection (null or an empty string for the default one), got '.get_debug_type($connection).'.');
        }
        $store = new StoreSourceSettings(
            enabled: $config->bool('firefly.feature-flags.sources.store.enabled', false),
            driver: $driver,
            connection: $connection === null || $connection === '' ? null : $connection,
            refreshInterval: $durations->get('firefly.feature-flags.sources.store.refresh-interval', 5.0),
        );

        $server = new ServerSettings(
            enabled: $config->bool('firefly.feature-flags.server.enabled', false),
            path: '/'.ltrim($config->string('firefly.feature-flags.server.path', '/feature-flags/flagd.json'), '/'),
            token: $config->string('firefly.feature-flags.server.token', ''),
            allowAnonymous: $config->bool('firefly.feature-flags.server.allow-anonymous', false),
        );
        if ($server->enabled && $server->token === '' && ! $server->allowAnonymous) {
            throw new ConfigurationException('firefly.feature-flags.server.enabled is on without firefly.feature-flags.server.token: set a token (clients send it as a bearer token) or set firefly.feature-flags.server.allow-anonymous to true.');
        }
        if ($server->enabled && $server->path === '/') {
            throw new ConfigurationException('firefly.feature-flags.server.enabled is on but firefly.feature-flags.server.path is the application root: name a path such as /feature-flags/flagd.json.');
        }

        return new self(
            enabled: $config->bool('firefly.feature-flags.enabled', false),
            flags: $config->array('firefly.feature-flags.flags', []),
            evaluators: $config->array('firefly.feature-flags.evaluators', []),
            file: $file,
            http: $http,
            store: $store,
            tenantAttribute: $config->string('firefly.feature-flags.context.tenant-attribute', 'tenant'),
            disabledStatus: self::disabledStatus($config),
            publishEvaluations: $config->bool('firefly.feature-flags.events.evaluations', false),
            writes: $config->bool('firefly.feature-flags.management.writes', false),
            server: $server,
        );
    }

    /** web.disabled-status, readable while the subsystem is off (the gate needs it). */
    public static function disabledStatus(Config $config): int
    {
        $status = $config->int('firefly.feature-flags.web.disabled-status', 404);
        if (! in_array($status, self::DISABLED_STATUSES, true)) {
            throw new ConfigurationException("firefly.feature-flags.web.disabled-status must be 404, 403 or 503, got [{$status}].");
        }

        return $status;
    }
}
