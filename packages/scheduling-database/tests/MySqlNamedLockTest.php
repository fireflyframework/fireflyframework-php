<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Scheduling\Database\MySqlNamedLock;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;

/** A resolver over one connection. */
function namedLockResolver(Connection $connection): ConnectionResolverInterface
{
    return new class($connection) implements ConnectionResolverInterface
    {
        public function __construct(private readonly Connection $connection) {}

        public function connection($name = null): Connection
        {
            return $this->connection;
        }

        public function getDefaultConnection(): string
        {
            return 'default';
        }

        public function setDefaultConnection($name): void {}
    };
}

it('asks GET_LOCK with a zero timeout and releases with RELEASE_LOCK', function () {
    $connection = new MySqlConnection(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => 'mysql', 'name' => 'pretend']);
    $lock = new MySqlNamedLock(namedLockResolver($connection));

    $log = $connection->pretend(function () use ($lock): void {
        // pretend() answers nothing, which GET_LOCK reports as an error (NULL): no lock.
        expect($lock->tryAcquire('reconcile', 30))->toBeFalse();
        $lock->release('reconcile');
    });

    // Under pretend() Laravel logs the SQL with its bindings substituted; the bindings array proves the name travelled
    // as a bound parameter, never concatenated into the statement.
    expect(array_column($log, 'query'))->toBe(["select get_lock('reconcile', 0) as locked", "select release_lock('reconcile') as released"])
        ->and(array_column($log, 'bindings'))->toBe([['reconcile'], ['reconcile']]);
});

it('keeps a name up to 64 bytes and hashes a longer one into the MySQL limit', function () {
    $long = str_repeat('App\\Jobs\\VeryLongNamespace\\', 4).'Reconcile::run';

    expect(MySqlNamedLock::key('reconcile'))->toBe('reconcile')
        ->and(MySqlNamedLock::key(str_repeat('x', 64)))->toBe(str_repeat('x', 64))
        ->and(MySqlNamedLock::key($long))->toBe('firefly:'.sha1($long))
        ->and(strlen(MySqlNamedLock::key($long)))->toBe(48);
});

it('refuses a connection that is not MySQL or MariaDB', function () {
    $lock = new MySqlNamedLock(namedLockResolver(new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:')));

    expect(fn () => $lock->tryAcquire('reconcile', 30))->toThrow(ConfigurationException::class, 'firefly.scheduling.lock.provider=mysql');
});
