<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Subscription;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Somebody who wants to be told: a URL, the event types it subscribes to (fnmatch patterns — `order.*`), the secret
 * its deliveries are signed with (null = unsigned), extra headers, whether it is active, and its own attempt budget
 * (null = firefly.callbacks.retry.max-attempts). endpoint() is the unit the circuit breaker guards — scheme, host
 * and port, so one partner's outage opens one breaker whatever paths its subscriptions use.
 */
final readonly class CallbackSubscription
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public array $eventTypes,
        public ?string $secret = null,
        public array $headers = [],
        public bool $active = true,
        public ?int $maxAttempts = null,
    ) {}

    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     */
    public static function create(string $name, string $url, array $eventTypes, ?string $secret = null, array $headers = [], ?int $maxAttempts = null): self
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)), $name, $url, $eventTypes, $secret, $headers, true, $maxAttempts);
    }

    /**
     * A subscription written in configuration (firefly.callbacks.subscriptions.N). A seed without an `id` gets one
     * derived from its URL and event types, so it is the same subscription — with the same execution history —
     * across deployments.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(array $raw, string $where): self
    {
        $url = $raw['url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException("{$where}.url must be an absolute URL.");
        }

        $types = $raw['event-types'] ?? null;
        if (! is_array($types) || $types === []) {
            throw new ConfigurationException("{$where}.event-types must list at least one event-type pattern.");
        }
        $eventTypes = [];
        foreach ($types as $type) {
            if (! is_string($type) || $type === '') {
                throw new ConfigurationException("{$where}.event-types must contain only non-empty strings.");
            }
            $eventTypes[] = $type;
        }

        $headers = [];
        foreach (is_array($raw['headers'] ?? null) ? $raw['headers'] : [] as $name => $value) {
            if (is_string($value)) {
                $headers[(string) $name] = $value;
            }
        }

        $id = is_string($raw['id'] ?? null) && $raw['id'] !== '' ? $raw['id'] : substr(hash('sha256', $url."\0".implode(',', $eventTypes)), 0, 32);
        $name = is_string($raw['name'] ?? null) && $raw['name'] !== '' ? $raw['name'] : (string) parse_url($url, PHP_URL_HOST);
        $secret = is_string($raw['secret'] ?? null) && $raw['secret'] !== '' ? $raw['secret'] : null;
        $maxAttempts = is_int($raw['max-attempts'] ?? null) && $raw['max-attempts'] > 0 ? $raw['max-attempts'] : null;

        return new self($id, $name, $url, $eventTypes, $secret, $headers, ($raw['active'] ?? true) !== false, $maxAttempts);
    }

    public function matches(string $eventType): bool
    {
        if (! $this->active) {
            return false;
        }

        foreach ($this->eventTypes as $pattern) {
            if (fnmatch($pattern, $eventType)) {
                return true;
            }
        }

        return false;
    }

    public function endpoint(): string
    {
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($this->url, PHP_URL_HOST));
        $port = parse_url($this->url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        return "{$scheme}://{$host}:{$port}";
    }
}
