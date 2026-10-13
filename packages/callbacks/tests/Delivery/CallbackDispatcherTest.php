<?php

declare(strict_types=1);

use Firefly\Callbacks\CallbacksSettings;
use Firefly\Callbacks\Delivery\CallbackDeliverer;
use Firefly\Callbacks\Delivery\CallbackDispatcher;
use Firefly\Callbacks\Delivery\CallbackSigner;
use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\InMemoryExecutionLog;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Subscription\InMemorySubscriptionRegistry;
use Firefly\Callbacks\Tests\Fixtures\ScriptedCallbackSender;
use Firefly\Eda\EventEnvelope;

/**
 * @return array{0: CallbackDispatcher, 1: InMemoryExecutionLog}
 */
function w4Dispatcher(ScriptedCallbackSender $sender): array
{
    $subscriptions = new InMemorySubscriptionRegistry([
        new CallbackSubscription('orders', 'orders', 'https://a.example.com/hooks', ['order.*'], 's'),
        new CallbackSubscription('billing', 'billing', 'https://b.example.com/hooks', ['invoice.*'], 's'),
    ]);
    $log = new InMemoryExecutionLog;
    $deliverer = new CallbackDeliverer(new CallbacksSettings(maxAttempts: 1), $sender, new CallbackSigner, null, static function (float $s): void {});

    return [new CallbackDispatcher($subscriptions, $log, $deliverer), $log];
}

it('delivers an event to the matching subscriptions only and logs each delivery', function () {
    $sender = new ScriptedCallbackSender;
    [$dispatcher, $log] = w4Dispatcher($sender);

    $executions = $dispatcher->dispatch(new EventEnvelope('order.created', 'orders', ['id' => 1], [], 'evt-1'));

    expect($executions)->toHaveCount(1)
        ->and($executions[0]->subscriptionId)->toBe('orders')
        ->and($sender->sent[0]['url'])->toBe('https://a.example.com/hooks')
        ->and($log->find($executions[0]->id)?->status)->toBe(CallbackStatus::Delivered);
});

it('never sends a redelivered event twice once it was delivered, but retries one that failed', function () {
    $sender = new ScriptedCallbackSender([204, 500, 204]);
    [$dispatcher, $log] = w4Dispatcher($sender);
    $delivered = new EventEnvelope('order.created', 'orders', [], [], 'evt-1');
    $failing = new EventEnvelope('order.created', 'orders', [], [], 'evt-2');

    $dispatcher->dispatch($delivered);
    $dispatcher->dispatch($delivered);
    $dispatcher->dispatch($failing);
    $retried = $dispatcher->dispatch($failing);

    expect($sender->sent)->toHaveCount(3)
        ->and($retried[0]->status)->toBe(CallbackStatus::Delivered)
        ->and($retried[0]->attempts)->toBe(2);
});

it('replays a logged execution through its subscription, and declines when the subscription is gone', function () {
    $sender = new ScriptedCallbackSender([500, 204]);
    [$dispatcher, $log] = w4Dispatcher($sender);
    $failed = $dispatcher->dispatch(new EventEnvelope('order.created', 'orders', [], [], 'evt-3'))[0];

    $replayed = $dispatcher->replay($failed);
    $orphan = new CallbackExecution('x', 'deleted', 'e', 't', 'https://a.example.com', '{}');

    expect($failed->status)->toBe(CallbackStatus::Failed)
        ->and($replayed?->status)->toBe(CallbackStatus::Delivered)
        ->and($log->find($failed->id)?->status)->toBe(CallbackStatus::Delivered)
        ->and($dispatcher->replay($orphan))->toBeNull();
});
