<?php

declare(strict_types=1);

use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Tests\Support\CallbacksCapstoneTestCase;
use Firefly\Webhooks\Signature\HmacSignatureValidator;
use Firefly\Webhooks\WebhookRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(CallbacksCapstoneTestCase::class);

/** The `data` member of a delivered callback body, or null when the body is not the documented JSON object. */
function w4CapstoneDeliveredData(string $body): mixed
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? ($decoded['data'] ?? null) : null;
}

it('delivers a published event as a signed POST that the webhooks hmac validator accepts', function () {
    /** @var CallbacksCapstoneTestCase $this */
    Http::fake(['partner.example.com/*' => Http::response('', 204)]);

    $this->events->publish('orders', 'order.created', ['id' => 7]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $first = is_array($values) ? ($values[0] ?? '') : $values;
            $headers[strtolower($name)] = is_string($first) ? $first : '';
        }

        (new HmacSignatureValidator('shared-secret', 'X-Firefly-Signature', 'sha256=', 'sha256', 'hex', 'X-Firefly-Timestamp', 300.0))
            ->verify(new WebhookRequest('partner', 'POST', $request->url(), $headers, $request->body(), [], 'application/json', time()));

        return $request->url() === 'https://partner.example.com/hooks/orders'
            && $request->header('X-Firefly-Event') === ['order.created']
            && w4CapstoneDeliveredData($request->body()) === ['id' => 7];
    });

    $logged = $this->app()->make(ExecutionLog::class)->recent();
    expect($logged)->toHaveCount(1)->and($logged[0]->status)->toBe(CallbackStatus::Delivered);
});

it('sends nothing for an event type no subscription wants', function () {
    /** @var CallbacksCapstoneTestCase $this */
    Http::fake();

    $this->events->publish('orders', 'order.shipped', ['id' => 7]);
    $this->events->publish('users', 'user.created', ['id' => 1]);

    Http::assertNothingSent();
});

it('retries a failing endpoint and records the failure without disturbing the publisher', function () {
    /** @var CallbacksCapstoneTestCase $this */
    Http::fake(['partner.example.com/*' => Http::sequence()->push('', 503)->push('', 503)->push('', 503)]);

    $this->events->publish('orders', 'order.created', ['id' => 8]);

    Http::assertSentCount(3);
    $failed = $this->app()->make(ExecutionLog::class)->recent(10, CallbackStatus::Failed);
    expect($failed)->toHaveCount(1)->and($failed[0]->attempts)->toBe(3)->and($failed[0]->responseStatus)->toBe(503);
});
