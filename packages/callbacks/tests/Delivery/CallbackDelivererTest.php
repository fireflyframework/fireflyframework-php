<?php

declare(strict_types=1);

use Firefly\Callbacks\CallbacksSettings;
use Firefly\Callbacks\Delivery\CallbackDeliverer;
use Firefly\Callbacks\Delivery\CallbackSigner;
use Firefly\Callbacks\Delivery\EndpointCircuitBreakers;
use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Tests\Fixtures\ScriptedCallbackSender;
use Firefly\Eda\EventEnvelope;
use Firefly\Resilience\Store\InMemoryResilienceStore;

/**
 * @return array{0: CallbackDeliverer, 1: ArrayObject<int, float>}
 */
function w4Deliverer(ScriptedCallbackSender $sender, CallbacksSettings $settings = new CallbacksSettings, ?EndpointCircuitBreakers $breakers = null): array
{
    /** @var ArrayObject<int, float> $slept */
    $slept = new ArrayObject;
    $deliverer = new CallbackDeliverer(
        $settings,
        $sender,
        new CallbackSigner,
        $breakers,
        static function (float $seconds) use ($slept): void {
            $slept[] = $seconds;
        },
        static fn (): int => 1_760_000_000,
    );

    return [$deliverer, $slept];
}

function w4Execution(CallbackSubscription $subscription, string $eventId = 'evt-1'): CallbackExecution
{
    return CallbackExecution::pending($subscription, new EventEnvelope('order.created', 'orders', ['id' => 7], [], $eventId), 1_760_000_000);
}

function w4Partner(?int $maxAttempts = null, string $url = 'https://partner.example.com/hooks'): CallbackSubscription
{
    return new CallbackSubscription('sub-1', 'partner', $url, ['order.*'], 'shared', ['X-Partner' => 'p1'], true, $maxAttempts);
}

it('delivers on a 2xx with the signature, the delivery id and the subscription headers', function () {
    $sender = new ScriptedCallbackSender([204]);
    [$deliverer] = w4Deliverer($sender);
    $execution = w4Execution(w4Partner());

    $result = $deliverer->deliver(w4Partner(), $execution);

    expect($result->status)->toBe(CallbackStatus::Delivered)
        ->and($result->attempts)->toBe(1)
        ->and($result->responseStatus)->toBe(204)
        ->and($sender->sent[0]['body'])->toBe($execution->body)
        ->and($sender->sent[0]['headers'])->toMatchArray([
            'X-Partner' => 'p1',
            'Content-Type' => 'application/json',
            'X-Firefly-Event' => 'order.created',
            'X-Firefly-Event-Id' => 'evt-1',
            'X-Firefly-Delivery' => $execution->id,
            'X-Firefly-Timestamp' => '1760000000',
            'X-Firefly-Signature' => 'sha256='.hash_hmac('sha256', '1760000000.'.$execution->body, 'shared'),
        ]);
});

it('retries 5xx, 429 and transport errors with exponential back-off, then delivers', function () {
    $sender = new ScriptedCallbackSender([503, 429, new RuntimeException('connection reset'), 200]);
    [$deliverer, $slept] = w4Deliverer($sender, new CallbacksSettings(maxAttempts: 4));

    $result = $deliverer->deliver(w4Partner(), w4Execution(w4Partner()));

    expect($result->status)->toBe(CallbackStatus::Delivered)
        ->and($result->attempts)->toBe(4)
        ->and($slept->getArrayCopy())->toBe([1.0, 2.0, 4.0]);
});

it('fails for good on a 4xx other than 408 and 429, without retrying', function () {
    $sender = new ScriptedCallbackSender([410]);
    [$deliverer] = w4Deliverer($sender);

    $result = $deliverer->deliver(w4Partner(), w4Execution(w4Partner()));

    expect($result->status)->toBe(CallbackStatus::Failed)
        ->and($result->attempts)->toBe(1)
        ->and($result->responseStatus)->toBe(410)
        ->and($result->lastError)->toContain('not retryable')
        ->and($sender->sent)->toHaveCount(1);
});

it('gives up after the attempts and records the last error', function () {
    $sender = new ScriptedCallbackSender([500, 500, 500]);
    [$deliverer] = w4Deliverer($sender);

    $result = $deliverer->deliver(w4Partner(), w4Execution(w4Partner()));

    expect($result->status)->toBe(CallbackStatus::Failed)->and($result->attempts)->toBe(3)->and($result->lastError)->toBe('HTTP 500');
});

it("honours the subscription's own attempt budget", function () {
    $sender = new ScriptedCallbackSender([500, 500]);
    [$deliverer] = w4Deliverer($sender);

    expect($deliverer->deliver(w4Partner(1), w4Execution(w4Partner(1)))->attempts)->toBe(1);
});

it('refuses plain http unless allowed, and hosts outside the allow-list', function () {
    $sender = new ScriptedCallbackSender;
    [$httpsOnly] = w4Deliverer($sender);
    [$allowList] = w4Deliverer($sender, new CallbacksSettings(allowedHosts: ['example.com']));

    $http = w4Partner(url: 'http://partner.example.com/hooks');
    $elsewhere = w4Partner(url: 'https://evil.test/hooks');
    $sub = w4Partner(url: 'https://hooks.example.com/x');

    expect($httpsOnly->deliver($http, w4Execution($http))->lastError)->toContain('require-https')
        ->and($allowList->deliver($elsewhere, w4Execution($elsewhere))->lastError)->toContain('allowed-hosts')
        ->and($allowList->deliver($sub, w4Execution($sub))->status)->toBe(CallbackStatus::Delivered)
        ->and($sender->sent)->toHaveCount(1);
});

it('opens the circuit for an endpoint after its failures and stops calling it', function () {
    $sender = new ScriptedCallbackSender([500, 500, 500]);
    [$deliverer] = w4Deliverer($sender, new CallbacksSettings(maxAttempts: 1), new EndpointCircuitBreakers(new InMemoryResilienceStore, 2, 60.0));

    $deliverer->deliver(w4Partner(), w4Execution(w4Partner(), 'e-1'));
    $deliverer->deliver(w4Partner(), w4Execution(w4Partner(), 'e-2'));
    $third = $deliverer->deliver(w4Partner(), w4Execution(w4Partner(), 'e-3'));

    expect($third->status)->toBe(CallbackStatus::Failed)
        ->and($third->lastError)->toContain('circuit open for https://partner.example.com:443')
        ->and($sender->sent)->toHaveCount(2);
});

it('refuses to deliver to an inactive subscription without sending anything, unless the caller includes inactive ones', function () {
    $sender = new ScriptedCallbackSender([204]);
    [$deliverer] = w4Deliverer($sender);
    $inactive = new CallbackSubscription('sub-1', 'partner', 'https://partner.example.com/hooks', ['order.*'], 'shared', [], false);
    $execution = w4Execution($inactive);

    $refused = $deliverer->deliver($inactive, $execution);

    expect($refused->status)->toBe(CallbackStatus::Failed)
        ->and($refused->attempts)->toBe(0)
        ->and($refused->lastError)->toContain('subscription [sub-1] is inactive')
        ->and($sender->sent)->toBe([]);

    $forced = $deliverer->deliver($inactive, $execution, includeInactive: true);

    expect($forced->status)->toBe(CallbackStatus::Delivered)
        ->and($sender->sent)->toHaveCount(1);
});

it('delivers to the subscription\'s current URL, not the one stored on the execution, and records the change', function () {
    $sender = new ScriptedCallbackSender([204]);
    [$deliverer] = w4Deliverer($sender);
    $execution = w4Execution(w4Partner(url: 'https://old.example.com/hooks'));

    $result = $deliverer->deliver(w4Partner(url: 'https://new.example.com/hooks'), $execution);

    expect($result->status)->toBe(CallbackStatus::Delivered)
        ->and($sender->sent[0]['url'])->toBe('https://new.example.com/hooks')
        ->and($result->url)->toBe('https://new.example.com/hooks');
});

it('runs the destination refusal on the subscription\'s current URL, whatever the stored one passed', function () {
    $sender = new ScriptedCallbackSender([204]);
    [$deliverer] = w4Deliverer($sender, new CallbacksSettings(allowedHosts: ['partner.example.com']));
    $execution = w4Execution(w4Partner());

    $result = $deliverer->deliver(w4Partner(url: 'https://169.254.169.254/latest/meta-data'), $execution);

    expect($result->status)->toBe(CallbackStatus::Failed)
        ->and($result->lastError)->toContain('allowed-hosts')
        ->and($result->url)->toBe('https://169.254.169.254/latest/meta-data')
        ->and($sender->sent)->toBe([]);
});
