<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Messaging\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Messaging\MessagingAutoConfiguration;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\SQLiteConnection;

function messagingDeadLetterResolver(): ConnectionResolverInterface
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
function messagingDeadLetterContainer(?string $store, bool $withDatabase = true): Container
{
    $container = new Container;
    $container->instance(Config::class, new Config(new Repository(['firefly' => ['messaging' => $store === null ? [] : ['dead-letter' => ['store' => $store]]]])));
    if ($withDatabase) {
        $container->instance(ConnectionResolverInterface::class, messagingDeadLetterResolver());
    }

    return $container;
}

it('selects the messaging dead-letter store by firefly.messaging.dead-letter.store', function () {
    expect((new MessagingAutoConfiguration)->deadLetterStore())->toBeInstanceOf(InMemoryDeadLetterStore::class)
        ->and((new MessagingAutoConfiguration)->deadLetterStore(messagingDeadLetterContainer(null)))->toBeInstanceOf(InMemoryDeadLetterStore::class)
        ->and((new MessagingAutoConfiguration)->deadLetterStore(messagingDeadLetterContainer('memory')))->toBeInstanceOf(InMemoryDeadLetterStore::class)
        ->and((new MessagingAutoConfiguration)->deadLetterStore(messagingDeadLetterContainer('database')))->toBeInstanceOf(DatabaseDeadLetterStore::class)
        ->and(fn () => (new MessagingAutoConfiguration)->deadLetterStore(messagingDeadLetterContainer('database', withDatabase: false)))->toThrow(ConfigurationException::class, 'firefly.messaging.dead-letter.store=database')
        ->and(fn () => (new MessagingAutoConfiguration)->deadLetterStore(messagingDeadLetterContainer('kafka')))->toThrow(ConfigurationException::class, 'memory|database');
});

it('boots an app without a database on the memory store: the resolver is made only when database is chosen', function () {
    $context = bootFireflyApp(['firefly' => ['messaging' => []]], [MessagingServiceProvider::class, MessagingWiringProvider::class]);

    expect($context->get(DeadLetterStore::class))->toBeInstanceOf(InMemoryDeadLetterStore::class);
});
