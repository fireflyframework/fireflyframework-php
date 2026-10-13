<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\Database\DatabaseOutboxSettings;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $eda */
function outboxSettings(array $eda): DatabaseOutboxSettings
{
    return DatabaseOutboxSettings::from(new Config(new Repository(['firefly' => ['eda' => $eda]])));
}

it('defaults to the application connection, auto claims, a five-minute lease', function () {
    expect(outboxSettings([]))->toEqual(new DatabaseOutboxSettings(null, 'firefly_eda_events', 3, 'auto', 300.0, 500));
});

it('reads the database keys', function () {
    expect(outboxSettings(['database' => [
        'connection' => 'mysql', 'channel' => 'orders', 'max-attempts' => 5, 'claim-strategy' => 'optimistic-lease',
        'lease-seconds' => 30, 'poll-interval-ms' => 250,
    ]]))->toEqual(new DatabaseOutboxSettings('mysql', 'orders', 5, 'optimistic-lease', 30.0, 250));
});

it('keeps every firefly.eda.postgres key working as an alias, and lets the database key win', function () {
    expect(outboxSettings(['postgres' => ['connection' => 'pgsql', 'channel' => 'pgchan', 'max_attempts' => 7]]))
        ->toEqual(new DatabaseOutboxSettings('pgsql', 'pgchan', 7, 'auto', 300.0, 500))
        ->and(outboxSettings([
            'database' => ['connection' => 'mysql', 'max-attempts' => 2],
            'postgres' => ['connection' => 'pgsql', 'max_attempts' => 7],
        ]))->toEqual(new DatabaseOutboxSettings('mysql', 'firefly_eda_events', 2, 'auto', 300.0, 500));
});

it('treats a blank connection as the default one and floors nonsense values', function () {
    expect(outboxSettings(['database' => ['connection' => '', 'max-attempts' => 0, 'lease-seconds' => 0, 'poll-interval-ms' => 1]]))
        ->toEqual(new DatabaseOutboxSettings(null, 'firefly_eda_events', 1, 'auto', 1.0, 10));
});

it('treats a connection key that is present but null (an unset env()) as the default connection', function () {
    expect(outboxSettings(['database' => ['connection' => null]])->connection)->toBeNull()
        ->and(outboxSettings(['postgres' => ['connection' => null]])->connection)->toBeNull();
});

it('lets a database key that is present but null fall back to its postgres alias, then to the default', function () {
    expect(outboxSettings([
        'database' => ['connection' => null, 'channel' => null, 'max-attempts' => null],
        'postgres' => ['connection' => 'pgsql', 'channel' => 'pgchan', 'max_attempts' => 7],
    ]))->toEqual(new DatabaseOutboxSettings('pgsql', 'pgchan', 7, 'auto', 300.0, 500))
        ->and(outboxSettings([
            'database' => ['connection' => null, 'channel' => null, 'max-attempts' => null],
            'postgres' => ['connection' => null, 'channel' => null, 'max_attempts' => null],
        ]))->toEqual(new DatabaseOutboxSettings(null, 'firefly_eda_events', 3, 'auto', 300.0, 500));
});
