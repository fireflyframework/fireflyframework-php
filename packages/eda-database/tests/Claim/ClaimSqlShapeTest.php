<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\Claim\OptimisticLeaseClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategy;
use Firefly\Eda\Database\Outbox\Claim\ReadPastClaim;
use Firefly\Eda\Database\Outbox\Claim\SkipLockedClaim;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SqlServerConnection;

/**
 * The SQL each strategy sends, compiled by each driver's real grammar over an in-memory sqlite PDO under pretend():
 * nothing executes, the candidate SELECT returns no ids, so only the claim statement itself is logged — exactly what
 * the server would receive. Under pretend() Laravel logs each statement with its bindings substituted by the
 * driver grammar, so the pinned strings also prove the lease instant is the UTC millisecond string LeaseClock writes.
 * The real servers run the same statements in the integration lanes.
 *
 * @return list<string>
 */
function claimSqlShape(string $driver, OutboxClaimStrategy $strategy): array
{
    $pdo = new PDO('sqlite::memory:');
    $config = ['driver' => $driver, 'name' => 'pretend'];
    $connection = match ($driver) {
        'pgsql' => new PostgresConnection($pdo, 'pretend', '', $config),
        'mysql' => new MySqlConnection($pdo, 'pretend', '', $config),
        'mariadb' => new MariaDbConnection($pdo, 'pretend', '', $config),
        'sqlsrv' => new SqlServerConnection($pdo, 'pretend', '', $config),
        default => throw new InvalidArgumentException('No grammar under test for '.$driver),
    };
    $claim = OutboxClaim::at('node-a', 5, new DateTimeImmutable('2026-10-12 10:00:00', new DateTimeZone('UTC')), 300);

    return array_column($connection->pretend(static function () use ($connection, $strategy, $claim): void {
        $strategy->claim($connection, $claim);
    }), 'query');
}

it('claims with FOR UPDATE SKIP LOCKED on pgsql', function () {
    expect(claimSqlShape('pgsql', new SkipLockedClaim))->toBe([
        'select "id" from "firefly_eda_outbox" where "status" = \'PENDING\' and ("claimed_until" is null or "claimed_until" < \'2026-10-12 10:00:00.000\') order by "id" asc limit 5 for update skip locked',
    ]);
});

it('claims with FOR UPDATE SKIP LOCKED under READ COMMITTED on mysql and mariadb', function (string $driver) {
    expect(claimSqlShape($driver, new SkipLockedClaim))->toBe([
        'set transaction isolation level read committed',
        'select `id` from `firefly_eda_outbox` where `status` = \'PENDING\' and (`claimed_until` is null or `claimed_until` < \'2026-10-12 10:00:00.000\') order by `id` asc limit 5 for update skip locked',
    ]);
})->with(['mysql', 'mariadb']);

it('claims with the UPDLOCK, READPAST, ROWLOCK table hint on sqlsrv', function () {
    expect(claimSqlShape('sqlsrv', new ReadPastClaim))->toBe([
        'select top 5 [id] from [firefly_eda_outbox] with (updlock, readpast, rowlock) where [status] = \'PENDING\' and ([claimed_until] is null or [claimed_until] < \'2026-10-12 10:00:00.000\') order by [id] asc',
    ]);
});

it('reads candidates WITHOUT a lock for the optimistic lease, on any grammar', function () {
    expect(claimSqlShape('mysql', new OptimisticLeaseClaim))->toBe([
        'select `id` from `firefly_eda_outbox` where `status` = \'PENDING\' and (`claimed_until` is null or `claimed_until` < \'2026-10-12 10:00:00.000\') order by `id` asc limit 5',
    ]);
});
