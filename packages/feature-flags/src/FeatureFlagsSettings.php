<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\Config\Config;
use Firefly\FeatureFlags\Settings\FileSourceSettings;
use Firefly\FeatureFlags\Settings\HttpSourceSettings;
use Firefly\FeatureFlags\Settings\ServerSettings;
use Firefly\FeatureFlags\Settings\StoreSourceSettings;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;

/**
 * firefly.feature-flags.*, read once. The `flags` and `evaluators` maps are kept verbatim: their keys are flag
 * and evaluator names, never settings. A malformed value refuses the boot here, naming the key.
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
        $file = new FileSourceSettings(
            enabled: $config->bool('firefly.feature-flags.sources.file.enabled', false),
            path: $config->string('firefly.feature-flags.sources.file.path', ''),
            refreshInterval: self::seconds($config->get('firefly.feature-flags.sources.file.refresh-interval', '5s'), 'firefly.feature-flags.sources.file.refresh-interval'),
        );
        if ($file->enabled && trim($file->path) === '') {
            throw new ConfigurationException('firefly.feature-flags.sources.file.enabled is on but firefly.feature-flags.sources.file.path is empty: name the flagd document (.json, .yaml or .yml) to watch.');
        }

        $http = new HttpSourceSettings(
            enabled: $config->bool('firefly.feature-flags.sources.http.enabled', false),
            url: $config->string('firefly.feature-flags.sources.http.url', ''),
            token: $config->string('firefly.feature-flags.sources.http.token', ''),
            refreshInterval: self::seconds($config->get('firefly.feature-flags.sources.http.refresh-interval', '30s'), 'firefly.feature-flags.sources.http.refresh-interval'),
            timeout: self::seconds($config->get('firefly.feature-flags.sources.http.timeout', '2s'), 'firefly.feature-flags.sources.http.timeout'),
        );
        if ($http->enabled && trim($http->url) === '') {
            throw new ConfigurationException('firefly.feature-flags.sources.http.enabled is on but firefly.feature-flags.sources.http.url is empty: name the sync endpoint to poll.');
        }

        $driver = $config->string('firefly.feature-flags.sources.store.driver', 'database');
        if (! in_array($driver, self::STORE_DRIVERS, true)) {
            throw new ConfigurationException("firefly.feature-flags.sources.store.driver must be 'database' or 'memory', got [{$driver}].");
        }
        $connection = $config->get('firefly.feature-flags.sources.store.connection');
        $store = new StoreSourceSettings(
            enabled: $config->bool('firefly.feature-flags.sources.store.enabled', false),
            driver: $driver,
            connection: is_string($connection) && $connection !== '' ? $connection : null,
            refreshInterval: self::seconds($config->get('firefly.feature-flags.sources.store.refresh-interval', '5s'), 'firefly.feature-flags.sources.store.refresh-interval'),
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

    /** A duration in the framework grammar ('500ms', '5s', 'PT1M') or a number of seconds; a bad one names its key. */
    private static function seconds(mixed $value, string $key): float
    {
        if (is_int($value) || is_float($value)) {
            return max(0.0, (float) $value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return Duration::parse($value);
            } catch (ConfigurationException $exception) {
                throw new ConfigurationException("Configuration key [{$key}] is not a duration this framework can parse. {$exception->getMessage()}", previous: $exception);
            }
        }

        throw new ConfigurationException("Configuration key [{$key}] must be a duration such as '5s', '500ms' or 'PT1M'.");
    }
}
