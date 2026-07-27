<?php

declare(strict_types=1);

use Firefly\Actuator\Health\Status;
use Firefly\Eda\Postgres\PostgresHealthIndicator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;

function resolverReturning(ConnectionInterface $connection): ConnectionResolverInterface
{
    return new class($connection) implements ConnectionResolverInterface
    {
        public function __construct(private readonly ConnectionInterface $connection) {}

        public function connection($name = null): ConnectionInterface
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

function resolverThrowing(string $message): ConnectionResolverInterface
{
    return new class($message) implements ConnectionResolverInterface
    {
        public function __construct(private readonly string $message) {}

        public function connection($name = null): ConnectionInterface
        {
            throw new RuntimeException($this->message);
        }

        public function getDefaultConnection(): string
        {
            return 'default';
        }

        public function setDefaultConnection($name): void {}
    };
}

it('reports UP when the connection answers select 1', function () {
    $resolver = resolverReturning(new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:'));

    $health = (new PostgresHealthIndicator($resolver))->health();

    expect($health->status)->toBe(Status::Up)
        ->and($health->details)->toBe(['broker' => 'postgres']);
});

it('reports DOWN with the error detail when the connection cannot answer', function () {
    $resolver = resolverThrowing('connection refused');

    $health = (new PostgresHealthIndicator($resolver))->health();

    expect($health->status)->toBe(Status::Down)
        ->and($health->details)->toHaveKey('error')
        ->and($health->details['error'])->toBe('connection refused')
        ->and($health->details['broker'])->toBe('postgres');
});
