<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Lease time, in one place. Every instant a claim writes or compares is the APPLICATION's clock in UTC, at
 * millisecond precision, formatted `Y-m-d H:i:s.v` — so the comparison `claimed_until < :now` is a plain comparison of
 * values PHP wrote, identical on every driver, with no per-driver "now() + interval" SQL and no session time zone in
 * the way. The price is the ShedLock one: nodes must keep their clocks synchronised (NTP) well within a lease.
 */
final class LeaseClock
{
    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public static function now(?Closure $clock = null): DateTimeImmutable
    {
        $now = $clock === null ? new DateTimeImmutable('now') : $clock();

        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    public static function plus(DateTimeImmutable $instant, float $seconds): DateTimeImmutable
    {
        $milliseconds = max(0, (int) round($seconds * 1000));
        $interval = new DateInterval('PT'.intdiv($milliseconds, 1000).'S');
        $interval->f = ($milliseconds % 1000) / 1000;

        return $instant->add($interval);
    }

    public static function format(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** `host:pid:random` — tells two processes on one node, and two claimants in one process, apart. */
    public static function claimant(): string
    {
        $host = gethostname();
        $pid = getmypid();

        return substr(is_string($host) && $host !== '' ? $host : 'host', 0, 80).':'.($pid === false ? 0 : $pid).':'.bin2hex(random_bytes(4));
    }
}
