<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Webhooks\Signature\HmacSignatureValidator;
use Firefly\Webhooks\WebhookRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Domain\Currency;

uses(LumenCallbacksTestCase::class);

/** The `data` member of a delivered callback body — the domain event's public fields — or null. */
function lumenCallbackData(string $body): mixed
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? ($decoded['data'] ?? null) : null;
}

it('posts a signed callback to the partner for each deposit, carrying the domain event as data', function () {
    /** @var LumenCallbacksTestCase $this */
    Http::fake(['partner.example.com/*' => Http::response('', 204)]);
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);

    /** @var string $id */
    $id = $commands->send(new OpenWallet('owner-partner', Currency::EUR));
    $commands->send(new Deposit($id, 700));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($id): bool {
        $headers = [];
        foreach ($request->headers() as $name => $values) {
            $first = is_array($values) ? ($values[0] ?? '') : $values;
            $headers[strtolower($name)] = is_string($first) ? $first : '';
        }

        // The partner's own check, as a LaraFly receiver on firefly/webhooks would run it: throws on a bad signature
        // or a timestamp outside the five-minute window.
        (new HmacSignatureValidator(LumenCallbacksTestCase::SECRET, 'X-Firefly-Signature', 'sha256=', 'sha256', 'hex', 'X-Firefly-Timestamp', 300.0))
            ->verify(new WebhookRequest('lumen', 'POST', $request->url(), $headers, $request->body(), [], 'application/json', time()));

        $data = lumenCallbackData($request->body());

        return $request->url() === 'https://partner.example.com/hooks/wallet'
            && $request->header('X-Firefly-Event') === ['FundsDeposited']
            && is_array($data)
            && ($data['walletId'] ?? null) === $id
            && ($data['amountMinor'] ?? null) === 700;
    });

    $logged = $this->app()->make(ExecutionLog::class)->recent();
    expect($logged)->toHaveCount(1)
        ->and($logged[0]->status)->toBe(CallbackStatus::Delivered);
})->group('lumen');

it('calls the partner for nothing it did not subscribe to', function () {
    /** @var LumenCallbacksTestCase $this */
    Http::fake();
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);

    $commands->send(new OpenWallet('owner-quiet', Currency::EUR));

    Http::assertNothingSent();
})->group('lumen');
