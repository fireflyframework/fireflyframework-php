<?php

declare(strict_types=1);

use Firefly\Webhooks\WebhookRequest;
use Illuminate\Http\Request;

it('captures the exact URL, lower-cased headers, the raw body and form parameters', function () {
    $request = Request::create('https://api.example.com/webhooks/twilio?b=2&a=1', 'POST', ['To' => '+1'], [], [], [
        'HTTP_X_TWILIO_SIGNATURE' => 'sig',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8',
        'REMOTE_ADDR' => '10.0.0.5',
    ], 'To=%2B1');

    $webhook = WebhookRequest::fromHttp($request, 'twilio', null, 1_760_000_000);

    expect($webhook->url)->toBe('https://api.example.com/webhooks/twilio?b=2&a=1')
        ->and($webhook->header('X-Twilio-Signature'))->toBe('sig')
        ->and($webhook->body)->toBe('To=%2B1')
        ->and($webhook->form)->toBe(['To' => '+1'])
        ->and($webhook->contentType)->toBe('application/x-www-form-urlencoded')
        ->and($webhook->sourceIp)->toBe('10.0.0.5')
        ->and($webhook->receivedAt)->toBe(1_760_000_000);
});

it('signs against the configured public URL when a proxy rewrites the host', function () {
    $request = Request::create('http://10.0.0.7:8080/webhooks/twilio?x=1', 'POST');

    expect(WebhookRequest::fromHttp($request, 'twilio', 'https://api.example.com/webhooks/twilio', 0)->url)->toBe('https://api.example.com/webhooks/twilio?x=1');
});
