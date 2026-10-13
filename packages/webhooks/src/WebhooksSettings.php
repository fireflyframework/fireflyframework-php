<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;
use Firefly\Webhooks\Provider\WebhookProviderSettings;
use Throwable;

/**
 * firefly.webhooks.*, read once and validated at boot. The idempotency store defaults to `cache`, not memory: under
 * PHP-FPM a memory store forgets every delivery when the request ends, so it would dedupe nothing (spec Decision 9).
 * Durations take the framework grammar (`5m`, `24h`, `90s`) or a number of seconds.
 */
final readonly class WebhooksSettings
{
    /** @var list<string> */
    public const array STORES = ['memory', 'cache', 'database'];

    /**
     * @param  array<string, WebhookProviderSettings>  $providers
     */
    public function __construct(
        public bool $enabled,
        public string $path,
        public int $maxBodyBytes,
        public string $destination,
        public string $eventType,
        public float $replayTolerance,
        public string $idempotencyStore,
        public float $idempotencyTtl,
        public string $idempotencyCacheStore,
        public ?string $idempotencyConnection,
        public array $providers,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $tolerance = self::seconds($config->get('firefly.webhooks.replay.tolerance'), 300.0, 'firefly.webhooks.replay.tolerance');

        $store = $config->string('firefly.webhooks.idempotency.store', 'cache');
        if (! in_array($store, self::STORES, true)) {
            throw new ConfigurationException("firefly.webhooks.idempotency.store must be memory, cache or database; got '{$store}'.");
        }

        $maxBody = $config->int('firefly.webhooks.max-body-bytes', 1048576);
        if ($maxBody < 1) {
            throw new ConfigurationException("firefly.webhooks.max-body-bytes must be a positive number of bytes; got {$maxBody}.");
        }

        $providers = [];
        foreach ($config->array('firefly.webhooks.providers', []) as $id => $raw) {
            if (! is_array($raw)) {
                throw new ConfigurationException("firefly.webhooks.providers.{$id} must be an array of provider settings.");
            }
            $providers[(string) $id] = WebhookProviderSettings::fromArray((string) $id, $raw, $tolerance);
        }

        $connection = $config->get('firefly.webhooks.idempotency.connection');

        return new self(
            enabled: $config->bool('firefly.webhooks.enabled', false),
            path: trim($config->string('firefly.webhooks.path', 'webhooks'), '/'),
            maxBodyBytes: $maxBody,
            destination: $config->string('firefly.webhooks.destination', 'webhooks.{provider}'),
            eventType: $config->string('firefly.webhooks.event-type', 'webhook.{provider}.{type}'),
            replayTolerance: $tolerance,
            idempotencyStore: $store,
            idempotencyTtl: self::seconds($config->get('firefly.webhooks.idempotency.ttl'), 86400.0, 'firefly.webhooks.idempotency.ttl'),
            idempotencyCacheStore: $config->string('firefly.webhooks.idempotency.cache-store', ''),
            idempotencyConnection: is_string($connection) && $connection !== '' ? $connection : null,
            providers: $providers,
        );
    }

    public function provider(string $id): ?WebhookProviderSettings
    {
        return $this->providers[$id] ?? null;
    }

    /** A duration setting in seconds: null takes the default; anything that is not a non-negative duration refuses. */
    public static function seconds(mixed $value, float $default, string $key): float
    {
        if ($value === null) {
            return $default;
        }

        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (float) $value;
        }

        if (is_string($value)) {
            try {
                return Duration::parse($value);
            } catch (Throwable $e) {
                throw new ConfigurationException("{$key} must be a duration such as 90s, 5m or 24h; got '{$value}'.", previous: $e);
            }
        }

        throw new ConfigurationException("{$key} must be a duration such as 90s, 5m or 24h, or a number of seconds.");
    }
}
