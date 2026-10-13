<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Messaging\DeadLetter\DeadLetterEntry;
use Firefly\Messaging\DeadLetter\DeadLetterQuery;
use Firefly\Messaging\DeadLetter\DeadLetterSchema;
use Firefly\Messaging\Message;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

uses(FireflyDatabaseTestCase::class);

beforeEach(function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000300_create_firefly_messaging_dead_letters_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $migration->up();
});

function messagingDeadLetterStore(string $now = '2026-10-12 10:00:00.500'): DatabaseDeadLetterStore
{
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    return new DatabaseDeadLetterStore($connections, null, static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')));
}

it('keeps raw bytes byte for byte — NUL, high bytes and all — with key, headers and cause', function () {
    $bytes = "\x00\x01\xFF\xFEpayload\n\x80";
    messagingDeadLetterStore()->store(new Message('ledger.DLT', $bytes, 'k-1', ['x-original-topic' => 'ledger', 'x-exception' => 'boom']), new LogicException('boom'));

    $entry = messagingDeadLetterStore()->all()[0];

    expect($entry->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($entry->message->value)->toBe($bytes)
        ->and($entry->message->topic)->toBe('ledger.DLT')
        ->and($entry->message->key)->toBe('k-1')
        ->and($entry->message->headers)->toBe(['x-original-topic' => 'ledger', 'x-exception' => 'boom'])
        ->and($entry->exceptionClass)->toBe(LogicException::class)
        ->and($entry->exceptionMessage)->toBe('boom')
        ->and($entry->deadLetteredAt?->format('Y-m-d H:i:s.v'))->toBe('2026-10-12 10:00:00.500')
        ->and(DB::table(DeadLetterSchema::TABLE)->value('original_topic'))->toBe('ledger');

    $stored = DB::table(DeadLetterSchema::TABLE)->value('value');
    expect(is_string($stored) ? base64_decode($stored, true) : null)->toBe($bytes);
});

it('keeps a message with no key and no headers', function () {
    messagingDeadLetterStore()->store(new Message('plain.DLT', ''), new RuntimeException('empty'));

    $entry = messagingDeadLetterStore()->all()[0];

    expect($entry->message->value)->toBe('')
        ->and($entry->message->key)->toBeNull()
        ->and($entry->message->headers)->toBe([])
        ->and(DB::table(DeadLetterSchema::TABLE)->value('original_topic'))->toBeNull();
});

it('selects by id and by either topic, oldest first, and removes one entry', function () {
    messagingDeadLetterStore('2026-10-12 10:00:02')->store(new Message('ledger.DLT', 'second', null, ['x-original-topic' => 'ledger']), new RuntimeException('b'));
    messagingDeadLetterStore('2026-10-12 10:00:01')->store(new Message('ledger.DLT', 'first', null, ['x-original-topic' => 'ledger']), new RuntimeException('a'));
    messagingDeadLetterStore('2026-10-12 10:00:03')->store(new Message('audit.DLT', 'third', null, ['x-original-topic' => 'audit']), new RuntimeException('c'));
    $store = messagingDeadLetterStore();

    $ledger = $store->find(new DeadLetterQuery(topic: 'ledger'));

    expect(array_map(static fn (DeadLetterEntry $e): string => $e->message->value, $ledger))->toBe(['first', 'second'])
        ->and($store->find(new DeadLetterQuery(topic: 'ledger.DLT')))->toHaveCount(2)
        ->and($store->find(new DeadLetterQuery(topic: 'audit.DLT')))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(limit: 2)))->toHaveCount(2)
        ->and($store->find(new DeadLetterQuery(ids: [(string) $ledger[1]->id]))[0]->message->value)->toBe('second')
        ->and($store->remove((string) $ledger[0]->id))->toBeTrue()
        ->and($store->remove((string) $ledger[0]->id))->toBeFalse()
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);
});

it('keeps the message when the cause carries raw bytes: the description is made valid UTF-8, the value is not touched', function () {
    $bytes = "\xC3\x28raw";
    messagingDeadLetterStore()->store(new Message('ledger.DLT', $bytes, null, ['x-original-topic' => 'ledger', 'x-exception' => "bad \xC3\x28 input"]), new RuntimeException("bad \xC3\x28 input"));

    $entry = messagingDeadLetterStore()->all()[0];

    expect($entry->message->value)->toBe($bytes)
        ->and(mb_check_encoding($entry->exceptionMessage, 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding($entry->message->headers['x-exception'] ?? '', 'UTF-8'))->toBeTrue()
        ->and($entry->message->headers['x-original-topic'] ?? null)->toBe('ledger');
});

it('keeps an opaque key byte for byte — longer than a varchar, and not valid UTF-8 — stored as base64 text', function (string $key) {
    messagingDeadLetterStore()->store(new Message('ledger.DLT', 'v', $key, ['x-original-topic' => 'ledger']), new RuntimeException('boom'));

    $entry = messagingDeadLetterStore()->all()[0];
    $stored = DB::table(DeadLetterSchema::TABLE)->value('message_key');

    expect($entry->message->key)->toBe($key)
        ->and(is_string($stored) ? base64_decode($stored, true) : null)->toBe($key)
        ->and(is_string($stored) && mb_check_encoding($stored, 'ASCII'))->toBeTrue();
})->with([
    'a 300-byte key' => [str_repeat('k', 300)],
    'a non-UTF-8 key' => ["\xFF\x00k"],
    'a raw 16-byte UUID' => [hex2bin('0192f5a1c3d47e8f9a0b1c2d3e4f5061')],
    'an empty key' => [''],
]);
