<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\DeadLetterFixtures;

use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Message;
use Firefly\Testing\Fixture\ListenerSpy;
use RuntimeException;

/** Fails while $failing is true (the outage); records each message it handled (value + replay marker) once it is false. */
final class FlakyLedgerListener
{
    public static bool $failing = true;

    public function __construct(private readonly ListenerSpy $spy) {}

    #[MessageListener(topic: 'ledger', deadLetterTopic: 'ledger.DLT')]
    public function onLedger(Message $message): void
    {
        if (self::$failing) {
            throw new RuntimeException('ledger unavailable');
        }

        $this->spy->record($message->value.'#'.($message->key ?? '-').'#'.($message->headers['x-replayed-from'] ?? 'first'));
    }
}
