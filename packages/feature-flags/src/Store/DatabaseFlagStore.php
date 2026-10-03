<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\FeatureFlags\Definition\FlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The `database` driver over any Laravel connection. Each write owns a transaction or a savepoint inside the caller's transaction: the flag row is read with
 * lockForUpdate (a no-op on SQLite), checked against
 * expectedVersion, then updated WHERE version = the version read — so a concurrent writer that slipped past
 * the read still loses — and one change row is appended. Definitions are stored as compact JSON with empty
 * objects kept as `{}` and times as UTC `Y-m-d H:i:s.u`; rows PyFly wrote (`json.dumps` spacing, microseconds)
 * read back the same.
 */
final class DatabaseFlagStore implements CommitAwareFlagStore, FlagStore, TransactionAwareFlagStore
{
    private readonly Closure $clock;

    private readonly Connection $connection;

    private readonly StoreCommitCallbacks $callbacks;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(ConnectionInterface $connection, ?Closure $clock = null)
    {
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('The database flag store requires an Illuminate database connection.');
        }
        $this->connection = $connection;
        $this->callbacks = new StoreCommitCallbacks($connection);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function transactionActive(): bool
    {
        return $this->connection->transactionLevel() > 0;
    }

    public function afterCommit(Closure $callback): void
    {
        $this->callbacks->afterCommit($callback);
    }

    public function all(): array
    {
        $flags = [];
        foreach ($this->connection->table(FeatureFlagSchema::FLAGS)->useWritePdo()->orderBy('flag_key')->get() as $row) {
            $flag = self::storedFlag($row);
            $flags[$flag->key] = $flag;
        }

        return $flags;
    }

    public function get(string $key): ?StoredFlag
    {
        $row = $this->connection->table(FeatureFlagSchema::FLAGS)->useWritePdo()->where('flag_key', $key)->first();

        return $row === null ? null : self::storedFlag($row);
    }

    public function revision(): int
    {
        $max = $this->connection->table(FeatureFlagSchema::CHANGES)->useWritePdo()->max('id');

        return is_numeric($max) ? (int) $max : 0;
    }

    public function put(string $key, array $definition, ?string $actor, ?int $expectedVersion = null): FlagChange
    {
        $definition = Json::members(Json::decode(self::encode($key, $definition)));

        /** @var FlagChange */
        return $this->connection->transaction(function () use ($key, $definition, $actor, $expectedVersion): FlagChange {
            $current = $this->locked($key);
            FlagStoreConflict::check($key, $expectedVersion, $current?->version);

            $now = ($this->clock)();
            $encoded = self::encode($key, $definition);
            $stamp = self::stamp($now);

            if ($current === null) {
                try {
                    $this->connection->table(FeatureFlagSchema::FLAGS)->insert([
                        'flag_key' => $key, 'definition' => $encoded, 'version' => 1, 'updated_at' => $stamp, 'updated_by' => $actor,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    throw new FlagStoreConflict($key, $expectedVersion, null);
                }
            } else {
                $updated = $this->connection->table(FeatureFlagSchema::FLAGS)
                    ->where('flag_key', $key)
                    ->where('version', $current->version)
                    ->update(['definition' => $encoded, 'version' => $current->version + 1, 'updated_at' => $stamp, 'updated_by' => $actor]);
                if ($updated !== 1) {
                    throw new FlagStoreConflict($key, $expectedVersion ?? $current->version, null);
                }
            }

            return $this->record($key, FlagChange::PUT, $definition, $current?->definition, $actor, $now);
        });
    }

    public function delete(string $key, ?string $actor, ?int $expectedVersion = null): ?FlagChange
    {
        /** @var FlagChange|null */
        return $this->connection->transaction(function () use ($key, $actor, $expectedVersion): ?FlagChange {
            $current = $this->locked($key);
            FlagStoreConflict::check($key, $expectedVersion, $current?->version);
            if ($current === null) {
                return null;
            }

            $deleted = $this->connection->table(FeatureFlagSchema::FLAGS)->where('flag_key', $key)->where('version', $current->version)->delete();
            if ($deleted !== 1) {
                throw new FlagStoreConflict($key, $expectedVersion ?? $current->version, null);
            }

            return $this->record($key, FlagChange::DELETE, null, $current->definition, $actor, ($this->clock)());
        });
    }

    public function history(string $key, int $limit = 50): array
    {
        if ($limit <= 0) {
            return [];
        }
        $changes = [];
        $rows = $this->connection->table(FeatureFlagSchema::CHANGES)->useWritePdo()->where('flag_key', $key)->orderByDesc('id')->limit(max(0, $limit))->get();
        foreach ($rows as $row) {
            $changes[] = new FlagChange(
                self::int($row, 'id'),
                self::string($row, 'flag_key'),
                self::string($row, 'action'),
                self::definition($row, 'definition'),
                self::definition($row, 'previous'),
                self::nullableString($row, 'actor'),
                self::instant(self::string($row, 'changed_at')),
            );
        }

        return $changes;
    }

    private function locked(string $key): ?StoredFlag
    {
        $row = $this->connection->table(FeatureFlagSchema::FLAGS)->where('flag_key', $key)->lockForUpdate()->first();

        return $row === null ? null : self::storedFlag($row);
    }

    /**
     * @param  array<array-key, mixed>|null  $definition
     * @param  array<array-key, mixed>|null  $previous
     */
    private function record(string $key, string $action, ?array $definition, ?array $previous, ?string $actor, DateTimeImmutable $now): FlagChange
    {
        $id = $this->connection->table(FeatureFlagSchema::CHANGES)->insertGetId([
            'flag_key' => $key,
            'action' => $action,
            'definition' => $definition === null ? null : self::encode($key, $definition),
            'previous' => $previous === null ? null : self::encode($key, $previous),
            'actor' => $actor,
            'changed_at' => self::stamp($now),
        ]);

        return new FlagChange((int) $id, $key, $action, $definition, $previous, $actor, $now);
    }

    /**
     * @param  array<array-key, mixed>  $definition
     */
    private static function encode(string $key, array $definition): string
    {
        return Json::canonical((new FlagDefinition($key, $definition))->toJsonValue());
    }

    private static function stamp(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function instant(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function storedFlag(object $row): StoredFlag
    {
        return new StoredFlag(
            self::string($row, 'flag_key'),
            self::definition($row, 'definition') ?? [],
            self::int($row, 'version'),
            self::instant(self::string($row, 'updated_at')),
            self::nullableString($row, 'updated_by'),
        );
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function definition(object $row, string $column): ?array
    {
        $value = self::nullableString($row, $column);

        return $value === null ? null : Json::members(Json::decode($value));
    }

    private static function string(object $row, string $column): string
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(object $row, string $column): ?string
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(object $row, string $column): int
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
