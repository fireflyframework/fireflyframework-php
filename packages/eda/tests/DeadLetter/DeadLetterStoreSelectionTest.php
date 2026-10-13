<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Eda\EdaAutoConfiguration;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;

function deadLetterConfig(?string $store): Config
{
    return new Config(new Repository(['firefly' => ['eda' => $store === null ? [] : ['dead-letter' => ['store' => $store]]]]));
}

function deadLetterResolver(): ConnectionResolverInterface
{
    return new class(new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:')) implements ConnectionResolverInterface
    {
        public function __construct(private readonly SQLiteConnection $connection) {}

        public function connection($name = null): SQLiteConnection
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

/** A container holding what a booted app holds: the Config, and the connection resolver unless $withDatabase is false. */
function deadLetterContainer(?string $store, bool $withDatabase = true): Container
{
    $container = new Container;
    $container->instance(Config::class, deadLetterConfig($store));
    if ($withDatabase) {
        $container->instance(ConnectionResolverInterface::class, deadLetterResolver());
    }

    return $container;
}

it('selects the dead-letter store by firefly.eda.dead-letter.store', function () {
    expect((new EdaAutoConfiguration)->deadLetterStore())->toBeInstanceOf(InMemoryDeadLetterStore::class)
        ->and((new EdaAutoConfiguration)->deadLetterStore(deadLetterContainer(null)))->toBeInstanceOf(InMemoryDeadLetterStore::class)
        ->and((new EdaAutoConfiguration)->deadLetterStore(deadLetterContainer('memory')))->toBeInstanceOf(InMemoryDeadLetterStore::class)
        ->and((new EdaAutoConfiguration)->deadLetterStore(deadLetterContainer('database')))->toBeInstanceOf(DatabaseDeadLetterStore::class)
        ->and(fn () => (new EdaAutoConfiguration)->deadLetterStore(deadLetterContainer('database', withDatabase: false)))->toThrow(ConfigurationException::class, 'firefly.eda.dead-letter.store=database')
        ->and(fn () => (new EdaAutoConfiguration)->deadLetterStore(deadLetterContainer('redis')))->toThrow(ConfigurationException::class, 'memory|database');
});

it('boots an app without a database on the memory store: the resolver is made only when database is chosen', function () {
    $context = bootFireflyApp(['firefly' => ['eda' => []]], [EdaServiceProvider::class, EdaWiringProvider::class]);

    expect($context->get(DeadLetterStore::class))->toBeInstanceOf(InMemoryDeadLetterStore::class);
});
