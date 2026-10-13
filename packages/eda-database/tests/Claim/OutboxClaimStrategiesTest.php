<?php

declare(strict_types=1);

use Firefly\Data\Capability\DatabaseCapabilities;
use Firefly\Eda\Database\Outbox\Claim\ConfiguredClaimStrategy;
use Firefly\Eda\Database\Outbox\Claim\OptimisticLeaseClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\Claim\ReadPastClaim;
use Firefly\Eda\Database\Outbox\Claim\SkipLockedClaim;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;

it('picks the strategy the server speaks when the key is auto', function (string $driver, string $version, string $expected) {
    expect(OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion($driver, $version))->name())->toBe($expected);
})->with([
    'pgsql' => ['pgsql', '16.15', 'skip-locked'],
    'mysql 8.4' => ['mysql', '8.4.11', 'skip-locked'],
    'mysql 5.7' => ['mysql', '5.7.44', 'optimistic-lease'],
    'mariadb 11' => ['mariadb', '11.8.9-MariaDB', 'skip-locked'],
    'mariadb 10.5' => ['mariadb', '10.5.25-MariaDB', 'optimistic-lease'],
    'sqlsrv' => ['sqlsrv', '16.00.4135', 'read-past'],
    'sqlite' => ['sqlite', '3.46.1', 'optimistic-lease'],
    'unknown' => ['firebird', '5.0', 'optimistic-lease'],
]);

it('honours a forced strategy the server supports and refuses one it does not', function () {
    expect(OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion('pgsql', '16.15'), 'optimistic-lease'))->toBeInstanceOf(OptimisticLeaseClaim::class)
        ->and(OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion('mysql', '8.4.11'), 'skip-locked'))->toBeInstanceOf(SkipLockedClaim::class)
        ->and(OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion('sqlsrv', '16.00'), 'read-past'))->toBeInstanceOf(ReadPastClaim::class)
        ->and(fn () => OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion('mysql', '5.7.44'), 'skip-locked'))
        ->toThrow(ConfigurationException::class, 'mysql 5.7.44')
        ->and(fn () => OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion('pgsql', '16.15'), 'read-past'))
        ->toThrow(ConfigurationException::class, 'firefly.eda.database.claim-strategy="read-past"')
        ->and(fn () => OutboxClaimStrategies::forCapabilities(DatabaseCapabilities::fromVersion('pgsql', '16.15'), 'fastest'))
        ->toThrow(ConfigurationException::class, 'auto|skip-locked|read-past|optimistic-lease');
});

it('resolves lazily, at the first claim, never at construction', function () {
    $strategy = OutboxClaimStrategies::configured('auto');
    expect($strategy)->toBeInstanceOf(ConfiguredClaimStrategy::class)
        ->and($strategy->name())->toBe('auto');

    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'lazy']);
    $connection->getSchemaBuilder()->create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));

    expect($strategy->claim($connection, OutboxClaim::at('a', 1, new DateTimeImmutable('now'), 1)))->toBe([])
        ->and($strategy->name())->toBe('optimistic-lease')
        ->and(OutboxClaimStrategies::configured('optimistic-lease'))->toBeInstanceOf(OptimisticLeaseClaim::class);
});
