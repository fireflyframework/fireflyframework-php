<?php

declare(strict_types=1);

namespace Firefly\Callbacks;

use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;
use Throwable;

/**
 * firefly.callbacks.*, read once and validated at boot. The retry defaults are deliberately small (three attempts,
 * 1s doubling, capped at 30s): attempts run INLINE where the event is consumed, so on firefly.eda.provider=memory
 * they hold the request that published the event — run deliveries in workers with the queue or a broker provider,
 * and re-send what still failed with `php artisan firefly:callbacks:replay` (spec Decision 13).
 */
final readonly class CallbacksSettings
{
    /** @var list<string> */
    public const array STORES = ['memory', 'database'];

    /**
     * @param  list<string>  $eventTypes
     * @param  list<CallbackSubscription>  $subscriptions
     * @param  list<string>  $allowedHosts
     */
    public function __construct(
        public bool $enabled = false,
        public array $eventTypes = [],
        public string $registryStore = 'memory',
        public string $logStore = 'memory',
        public ?string $connection = null,
        public array $subscriptions = [],
        public int $maxAttempts = 3,
        public float $initialDelay = 1.0,
        public float $multiplier = 2.0,
        public float $maxDelay = 30.0,
        public float $timeout = 10.0,
        public bool $requireHttps = true,
        public array $allowedHosts = [],
        public bool $breakerEnabled = true,
        public int $failureThreshold = 5,
        public float $waitDurationInOpen = 30.0,
        public string $signatureHeader = 'X-Firefly-Signature',
        public string $timestampHeader = 'X-Firefly-Timestamp',
    ) {}

    public static function fromConfig(Config $config): self
    {
        $registry = self::store($config->string('firefly.callbacks.registry.store', 'memory'), 'firefly.callbacks.registry.store');
        $log = self::store($config->string('firefly.callbacks.log.store', 'memory'), 'firefly.callbacks.log.store');

        $maxAttempts = $config->int('firefly.callbacks.retry.max-attempts', 3);
        if ($maxAttempts < 1) {
            throw new ConfigurationException("firefly.callbacks.retry.max-attempts must be at least 1; got {$maxAttempts}.");
        }

        $threshold = $config->int('firefly.callbacks.circuit-breaker.failure-threshold', 5);
        if ($threshold < 1) {
            throw new ConfigurationException("firefly.callbacks.circuit-breaker.failure-threshold must be at least 1; got {$threshold}.");
        }

        $multiplier = $config->get('firefly.callbacks.retry.multiplier');
        $connection = $config->get('firefly.callbacks.connection');

        $subscriptions = [];
        foreach ($config->array('firefly.callbacks.subscriptions', []) as $index => $raw) {
            if (! is_array($raw)) {
                throw new ConfigurationException("firefly.callbacks.subscriptions.{$index} must be an array.");
            }
            $subscriptions[] = CallbackSubscription::fromArray($raw, "firefly.callbacks.subscriptions.{$index}");
        }

        return new self(
            enabled: $config->bool('firefly.callbacks.enabled', false),
            eventTypes: self::strings($config->array('firefly.callbacks.event-types', []), 'firefly.callbacks.event-types'),
            registryStore: $registry,
            logStore: $log,
            connection: is_string($connection) && $connection !== '' ? $connection : null,
            subscriptions: $subscriptions,
            maxAttempts: $maxAttempts,
            initialDelay: self::seconds($config->get('firefly.callbacks.retry.initial-delay'), 1.0, 'firefly.callbacks.retry.initial-delay'),
            multiplier: is_numeric($multiplier) && (float) $multiplier >= 1.0 ? (float) $multiplier : 2.0,
            maxDelay: self::seconds($config->get('firefly.callbacks.retry.max-delay'), 30.0, 'firefly.callbacks.retry.max-delay'),
            timeout: self::seconds($config->get('firefly.callbacks.http.timeout'), 10.0, 'firefly.callbacks.http.timeout'),
            requireHttps: $config->bool('firefly.callbacks.http.require-https', true),
            allowedHosts: array_map('strtolower', self::strings($config->array('firefly.callbacks.http.allowed-hosts', []), 'firefly.callbacks.http.allowed-hosts')),
            breakerEnabled: $config->bool('firefly.callbacks.circuit-breaker.enabled', true),
            failureThreshold: $threshold,
            waitDurationInOpen: self::seconds($config->get('firefly.callbacks.circuit-breaker.wait-duration-in-open'), 30.0, 'firefly.callbacks.circuit-breaker.wait-duration-in-open'),
            signatureHeader: $config->string('firefly.callbacks.signature.header', 'X-Firefly-Signature'),
            timestampHeader: $config->string('firefly.callbacks.signature.timestamp-header', 'X-Firefly-Timestamp'),
        );
    }

    private static function store(string $value, string $key): string
    {
        if (! in_array($value, self::STORES, true)) {
            throw new ConfigurationException("{$key} must be memory or database; got '{$value}'.");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values, string $key): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw new ConfigurationException("{$key} must be a list of non-empty strings.");
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private static function seconds(mixed $value, float $default, string $key): float
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
                throw new ConfigurationException("{$key} must be a duration such as 250ms, 10s or 1m; got '{$value}'.", previous: $e);
            }
        }

        throw new ConfigurationException("{$key} must be a duration such as 250ms, 10s or 1m, or a number of seconds.");
    }
}
