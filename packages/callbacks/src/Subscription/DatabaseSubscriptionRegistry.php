<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Subscription;

use Closure;
use Firefly\Callbacks\Schema\CallbackSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;

/**
 * registry.store=database: subscriptions as rows of firefly_callback_subscriptions on any Laravel connection.
 * save() is INSERT, and on a primary-key violation UPDATE — never "update, and insert when no row changed", because
 * MySQL reports zero affected rows for an update that changes nothing and that shape would then insert a duplicate.
 * The insert runs in a nested transaction so a violation inside a caller's Postgres transaction leaves it usable.
 * matching() reads the active rows and filters patterns in PHP: fnmatch() has no portable SQL equivalent, and a
 * registry is small. The secret is stored as given; encrypt it before save() if your threat model needs that.
 */
final class DatabaseSubscriptionRegistry implements SubscriptionRegistry
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(private readonly ConnectionInterface $connection, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function save(CallbackSubscription $subscription): void
    {
        $now = ($this->clock)();
        $row = [
            'name' => $subscription->name,
            'url' => $subscription->url,
            'event_types' => json_encode($subscription->eventTypes, JSON_THROW_ON_ERROR),
            'secret' => $subscription->secret,
            'headers' => json_encode((object) $subscription->headers, JSON_THROW_ON_ERROR),
            'active' => $subscription->active,
            'max_attempts' => $subscription->maxAttempts,
            'updated_at' => $now,
        ];

        try {
            $this->connection->transaction(fn (): bool => $this->connection->table(CallbackSchema::SUBSCRIPTIONS)->insert(['id' => $subscription->id, 'created_at' => $now] + $row));
        } catch (UniqueConstraintViolationException) {
            $this->connection->table(CallbackSchema::SUBSCRIPTIONS)->where('id', $subscription->id)->update($row);
        }
    }

    public function find(string $id): ?CallbackSubscription
    {
        $row = $this->connection->table(CallbackSchema::SUBSCRIPTIONS)->where('id', $id)->first();

        return is_object($row) ? self::hydrate($row) : null;
    }

    public function delete(string $id): bool
    {
        return $this->connection->table(CallbackSchema::SUBSCRIPTIONS)->where('id', $id)->delete() > 0;
    }

    public function all(): array
    {
        $subscriptions = [];
        foreach ($this->connection->table(CallbackSchema::SUBSCRIPTIONS)->orderBy('created_at')->orderBy('id')->get() as $row) {
            $subscriptions[] = self::hydrate($row);
        }

        return $subscriptions;
    }

    public function matching(string $eventType): array
    {
        return array_values(array_filter($this->all(), static fn (CallbackSubscription $s): bool => $s->matches($eventType)));
    }

    private static function hydrate(object $row): CallbackSubscription
    {
        $values = get_object_vars($row);

        try {
            $types = json_decode(self::text($values['event_types'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
            $headers = json_decode(self::text($values['headers'] ?? '{}'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $types = [];
            $headers = [];
        }

        $eventTypes = [];
        foreach (is_array($types) ? $types : [] as $type) {
            if (is_string($type)) {
                $eventTypes[] = $type;
            }
        }

        $stringHeaders = [];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            if (is_string($value)) {
                $stringHeaders[(string) $name] = $value;
            }
        }

        $maxAttempts = $values['max_attempts'] ?? null;
        $secret = $values['secret'] ?? null;

        return new CallbackSubscription(
            self::text($values['id'] ?? ''),
            self::text($values['name'] ?? ''),
            self::text($values['url'] ?? ''),
            $eventTypes,
            is_string($secret) ? $secret : null,
            $stringHeaders,
            (bool) ($values['active'] ?? false),
            is_numeric($maxAttempts) ? (int) $maxAttempts : null,
        );
    }

    /** A column read back as text: drivers return strings (or ints for numeric-looking ids), never anything else here. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
