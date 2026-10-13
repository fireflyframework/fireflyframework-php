<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\Scheduling\Lock\DistributedLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * A ShedLock-style lease table on any Laravel database connection: `firefly.scheduling.lock.provider=database`.
 *
 * tryAcquire() takes an ENDED lease with one conditional `UPDATE … WHERE name = ? AND locked_until <= now` (of two
 * nodes racing for it exactly one changes the row), and otherwise creates the row with an INSERT — a duplicate key
 * means another node holds a live lease, and the answer is false. The INSERT runs inside Connection::transaction(),
 * which is a SAVEPOINT when the caller already has a transaction open: on PostgreSQL a failed statement aborts the
 * whole transaction, and a lock attempt must never poison the caller's unit of work. release() deletes the row only
 * while it still carries THIS acquisition's token — a job that ran past its TTL does not release the lease another
 * node has taken since.
 *
 * Unlike PgAdvisoryLock and MySqlNamedLock the TTL is honoured on every driver: a hung job blocks its schedule for at
 * most $ttlSeconds. No connection is held while the job runs. Lease instants are the application clock in UTC
 * (millisecond precision), so nodes must keep their clocks synchronised well within the TTLs — the ShedLock rule.
 */
final class DatabaseLeaseLock implements DistributedLock
{
    public const int MAX_NAME_LENGTH = 255;

    private readonly string $owner;

    /** @var array<string, string> name => the locked_by token of this instance's live acquisition */
    private array $held = [];

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
        ?string $owner = null,
        private readonly ?Closure $clock = null,
    ) {
        $host = gethostname();
        $pid = getmypid();
        $this->owner = substr($owner ?? ((is_string($host) && $host !== '' ? $host : 'host').':'.($pid === false ? 0 : $pid)), 0, 200);
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('A lock name is at most %d characters; got %d.', self::MAX_NAME_LENGTH, strlen($name)));
        }

        $db = $this->db();
        $now = ($this->clock === null ? new DateTimeImmutable : ($this->clock)())->setTimezone(new DateTimeZone('UTC'));
        $token = $this->owner.'/'.bin2hex(random_bytes(4));
        $lease = [
            'locked_by' => $token,
            'locked_until' => self::format(self::plus($now, $ttlSeconds)),
            'locked_at' => self::format($now),
        ];

        $taken = $db->table(LockSchema::TABLE)
            ->where('name', $name)
            ->where('locked_until', '<=', self::format($now))
            ->update($lease);

        if ($taken !== 1) {
            try {
                $db->transaction(static fn () => $db->table(LockSchema::TABLE)->insert(['name' => $name, ...$lease]));
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        $this->held[$name] = $token;

        return true;
    }

    public function release(string $name): void
    {
        $token = $this->held[$name] ?? null;
        if ($token === null) {
            return;
        }

        unset($this->held[$name]);
        $this->db()->table(LockSchema::TABLE)->where('name', $name)->where('locked_by', $token)->delete();
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }

    private static function plus(DateTimeImmutable $instant, float $seconds): DateTimeImmutable
    {
        $milliseconds = max(1, (int) round($seconds * 1000));
        $interval = new DateInterval('PT'.intdiv($milliseconds, 1000).'S');
        $interval->f = ($milliseconds % 1000) / 1000;

        return $instant->add($interval);
    }

    private static function format(DateTimeImmutable $instant): string
    {
        return $instant->format('Y-m-d H:i:s.v');
    }
}
