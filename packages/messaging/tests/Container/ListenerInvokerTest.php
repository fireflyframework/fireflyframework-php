<?php

declare(strict_types=1);

use Firefly\Messaging\Container\DeadLetterHeaders;
use Firefly\Messaging\Container\ExponentialBackOff;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Message;

it('delivers on the first attempt without sleeping', function () {
    $slept = [];
    $outcome = (new ListenerInvoker(function (float $s) use (&$slept): void {
        $slept[] = $s;
    }))->invoke(static function (Message $m): void {}, new Message('t', 'v'), new RetryPolicy(3));

    expect($outcome->handled)->toBeTrue()->and($outcome->attempts)->toBe(1)->and($slept)->toBe([]);
});

it('retries with the back-off between attempts and succeeds on a later one', function () {
    $slept = [];
    $calls = 0;
    $outcome = (new ListenerInvoker(function (float $s) use (&$slept): void {
        $slept[] = $s;
    }))->invoke(function () use (&$calls): void {
        if (++$calls < 3) {
            throw new RuntimeException('not yet');
        }
    }, new Message('t', 'v'), new RetryPolicy(5, new ExponentialBackOff(1.0, 2.0, 30.0)));

    expect($outcome->handled)->toBeTrue()->and($outcome->attempts)->toBe(3)->and($slept)->toBe([1.0, 2.0]);
});

it('gives up after the last attempt and reports why, for the dead-letter headers', function () {
    $outcome = (new ListenerInvoker(static function (float $s): void {}))
        ->invoke(static function (): void {
            throw new DomainException('broken payload');
        }, new Message('orders', 'v'), new RetryPolicy(2));

    expect($outcome->handled)->toBeFalse()
        ->and($outcome->attempts)->toBe(2)
        ->and($outcome->failure)->toBeInstanceOf(DomainException::class)
        ->and(DeadLetterHeaders::for(new Message('orders', 'v'), $outcome))->toBe([
            'x-original-topic' => 'orders',
            'x-exception' => 'DomainException',
            'x-exception-message' => 'broken payload',
            'x-delivery-attempts' => '2',
        ]);
});
