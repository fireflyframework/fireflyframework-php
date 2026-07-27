<?php

declare(strict_types=1);

use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Tests\Fixtures\ScriptedEventConsumer;

it('polls up to max-messages, delivers each to the sink, acks, and stops', function () {
    $e1 = new EventEnvelope('user.created', 'users', ['id' => 1]);
    $e2 = new EventEnvelope('user.created', 'users', ['id' => 2]);
    $consumer = new ScriptedEventConsumer([$e1, $e2]);
    $seen = [];

    $count = (new ConsumerLoop)->run(
        $consumer,
        function (EventEnvelope $envelope) use (&$seen): void {
            $seen[] = $envelope->payload['id'];
        },
        new ConsumerOptions(maxMessages: 2, pollTimeoutMs: 10),
    );

    expect($count)->toBe(2)
        ->and($seen)->toBe([1, 2])
        ->and($consumer->started)->toBeTrue()
        ->and($consumer->stopped)->toBeTrue()
        ->and($consumer->acked)->toHaveCount(2)
        ->and($consumer->nacked)->toHaveCount(0);
});

it('nacks (requeue) when the sink throws — an exhausted-no-DLQ handler', function () {
    $consumer = new ScriptedEventConsumer([new EventEnvelope('x', 'd', [])]);

    $count = (new ConsumerLoop)->run(
        $consumer,
        function (): void {
            throw new RuntimeException('handler exhausted');
        },
        new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10),
    );

    expect($count)->toBe(1)
        ->and($consumer->acked)->toHaveCount(0)
        ->and($consumer->nacked)->toHaveCount(1);
});
