<?php

declare(strict_types=1);

use Firefly\Callbacks\Delivery\CallbackSigner;
use Firefly\Webhooks\Signature\HmacSignatureValidator;
use Firefly\Webhooks\WebhookRequest;

it('signs "<timestamp>.<body>" with HMAC-SHA256, the scheme the webhooks hmac validator verifies', function () {
    $body = '{"eventType":"order.created"}';
    $headers = (new CallbackSigner)->sign($body, 'shared', 1_760_000_000);

    expect($headers)->toBe([
        'X-Firefly-Timestamp' => '1760000000',
        'X-Firefly-Signature' => 'sha256='.hash_hmac('sha256', '1760000000.'.$body, 'shared'),
    ]);

    $receiver = new HmacSignatureValidator('shared', 'X-Firefly-Signature', 'sha256=', 'sha256', 'hex', 'X-Firefly-Timestamp', 300.0, static fn (): int => 1_760_000_100);
    $receiver->verify(new WebhookRequest('partner', 'POST', 'https://x', array_change_key_case($headers), $body, [], 'application/json', 1_760_000_100));
});

it('sends unsigned when the subscription has no secret', function () {
    expect((new CallbackSigner)->sign('{}', null, 1))->toBe([])
        ->and((new CallbackSigner)->sign('{}', '', 1))->toBe([]);
});
