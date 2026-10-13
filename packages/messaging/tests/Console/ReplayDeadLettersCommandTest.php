<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Messaging\DeadLetter\DeadLetterSchema;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingConsoleServiceProvider;
use Firefly\Messaging\Tests\DeadLetterFixtures\FlakyLedgerListener;
use Firefly\Messaging\Tests\Support\MessagingDeadLetterCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Support\Facades\DB;

uses(MessagingDeadLetterCapstoneTestCase::class);

it('keeps an exhausted message durably and replays it to its original topic, bytes and key unchanged', function () {
    /** @var MessagingDeadLetterCapstoneTestCase $this */
    $app = $this->app();
    $app->make(MessageBrokerPort::class)->publish('ledger', "entry-\x00-1", 'k');
    $app->make(MessageBrokerPort::class)->publish('ledger', "entry-\xFF-2", 'k');

    expect($app->make(DeadLetterStore::class))->toBeInstanceOf(DatabaseDeadLetterStore::class)
        ->and(DB::table(DeadLetterSchema::TABLE)->where('topic', 'ledger.DLT')->where('original_topic', 'ledger')->count())->toBe(2);

    // Dry run: lists, changes nothing.
    expect($this->replay(['--dry-run' => true]))->toBe(0)
        ->and($this->replayOutput())->toContain('would replay')
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);

    // The outage is over: replay one, then the rest.
    FlakyLedgerListener::$failing = false;
    expect($this->replay(['--topic' => 'ledger', '--limit' => 1]))->toBe(0)
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(1)
        ->and($this->replay(['--topic' => 'ledger.DLT']))->toBe(0)
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(0);

    /** @var ListenerSpy $spy */
    $spy = $app->make(ListenerSpy::class);
    expect($spy->seen)->toHaveCount(2)
        ->and($spy->seen[0])->toStartWith("entry-\x00-1#k#")
        ->and($spy->seen[0])->not->toEndWith('#first')
        ->and($spy->seen[1])->toStartWith("entry-\xFF-2#k#");
});

it('drops the retry headers and marks the replay with x-replayed-from', function () {
    /** @var MessagingDeadLetterCapstoneTestCase $this */
    $app = $this->app();
    $app->make(MessageBrokerPort::class)->publish('ledger', 'v', null, ['trace' => 't-1']);
    $id = DB::table(DeadLetterSchema::TABLE)->value('id');

    /** @var list<Message> $seen */
    $seen = [];
    $app->make(MessageBrokerPort::class)->subscribe('ledger', static function (Message $message) use (&$seen): void {
        $seen[] = $message;
    });
    FlakyLedgerListener::$failing = false;

    expect($this->replay(['--id' => [$id]]))->toBe(0)
        ->and($seen)->toHaveCount(1)
        ->and($seen[0]->headers)->toBe(['trace' => 't-1', 'x-replayed-from' => $id]);
});

it('dead-letters a replay that fails again, and --keep leaves the original in place', function () {
    /** @var MessagingDeadLetterCapstoneTestCase $this */
    $this->app()->make(MessageBrokerPort::class)->publish('ledger', 'still-failing');

    expect($this->replay(['--keep' => true]))->toBe(0)
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);
});

it('keeps an entry whose republish throws, and exits 1', function () {
    /** @var MessagingDeadLetterCapstoneTestCase $this */
    $this->app()->make(MessageBrokerPort::class)->publish('ledger', 'v');
    $this->app()->instance(MessageBrokerPort::class, new class implements MessageBrokerPort
    {
        public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
        {
            throw new RuntimeException('broker down');
        }

        public function subscribe(string $topic, callable $handler, ?string $group = null): void {}

        public function start(): void {}

        public function stop(): void {}
    });

    expect($this->replay())->toBe(1)
        ->and($this->replayOutput())->toContain('could not replay')
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(1);
});

it('refuses a bound DeadLetterStore that cannot select or remove entries, and exits 1', function () {
    /** @var MessagingDeadLetterCapstoneTestCase $this */
    $this->app()->instance(DeadLetterStore::class, new class implements DeadLetterStore
    {
        public function store(Message $message, Throwable $cause): void {}

        public function all(): array
        {
            return [];
        }
    });

    expect($this->replay())->toBe(1)
        ->and($this->replayOutput())->toContain('cannot select or remove entries');
});

it('ships its migration when the store is database', function () {
    /** @var MessagingDeadLetterCapstoneTestCase $this */
    expect($this->app()->make('migrator')->paths())->toContain(MessagingConsoleServiceProvider::MIGRATIONS);
});
