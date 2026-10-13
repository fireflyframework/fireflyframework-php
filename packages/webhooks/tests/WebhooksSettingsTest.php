<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Webhooks\Provider\SignatureKind;
use Firefly\Webhooks\WebhooksSettings;
use Illuminate\Config\Repository;

/**
 * @param  array<array-key, mixed>  $webhooks  a firefly.webhooks subtree, as a config file or a dataset spells it
 */
function w4WebhooksSettings(array $webhooks): WebhooksSettings
{
    return WebhooksSettings::fromConfig(new Config(new Repository(['firefly' => ['webhooks' => $webhooks]])));
}

it('has safe defaults: off, cache idempotency for a day, a five-minute replay window', function () {
    $settings = w4WebhooksSettings([]);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->path)->toBe('webhooks')
        ->and($settings->maxBodyBytes)->toBe(1048576)
        ->and($settings->destination)->toBe('webhooks.{provider}')
        ->and($settings->eventType)->toBe('webhook.{provider}.{type}')
        ->and($settings->replayTolerance)->toBe(300.0)
        ->and($settings->idempotencyStore)->toBe('cache')
        ->and($settings->idempotencyTtl)->toBe(86400.0)
        ->and($settings->providers)->toBe([]);
});

it('fills each provider kind with the headers that provider actually sends', function () {
    $settings = w4WebhooksSettings(['providers' => [
        'stripe' => ['validator' => 'stripe', 'secret' => 's'],
        'github' => ['validator' => 'github', 'secret' => 'g'],
        'twilio' => ['validator' => 'twilio', 'secret' => 't', 'public-url' => 'https://api.example.com/webhooks/twilio'],
        'acme' => ['secret' => 'a', 'timestamp-header' => 'X-Timestamp', 'tolerance' => '90s', 'destination' => 'partners.acme'],
        'open' => ['validator' => 'none'],
    ]]);

    expect($settings->provider('stripe')?->signatureHeader)->toBe('Stripe-Signature')
        ->and($settings->provider('stripe')?->idempotencyField)->toBe('id')
        ->and($settings->provider('github')?->signatureHeader)->toBe('X-Hub-Signature-256')
        ->and($settings->provider('github')?->idempotencyHeader)->toBe('X-GitHub-Delivery')
        ->and($settings->provider('github')?->eventTypeHeader)->toBe('X-GitHub-Event')
        ->and($settings->provider('twilio')?->idempotencyHeader)->toBe('I-Twilio-Idempotency-Token')
        ->and($settings->provider('twilio')?->publicUrl)->toBe('https://api.example.com/webhooks/twilio')
        ->and($settings->provider('acme')?->validator)->toBe(SignatureKind::Hmac)
        ->and($settings->provider('acme')?->signatureHeader)->toBe('X-Signature')
        ->and($settings->provider('acme')?->signaturePrefix)->toBe('sha256=')
        ->and($settings->provider('acme')?->tolerance)->toBe(90.0)
        ->and($settings->provider('acme')?->destination)->toBe('partners.acme')
        ->and($settings->provider('open')?->validator)->toBe(SignatureKind::None)
        ->and($settings->provider('missing'))->toBeNull();
});

it('refuses at boot what could never verify anything', function (array $webhooks, string $message) {
    expect(fn () => w4WebhooksSettings($webhooks))->toThrow(ConfigurationException::class, $message);
})->with([
    'unknown validator' => [['providers' => ['x' => ['validator' => 'paypal', 'secret' => 's']]], 'firefly.webhooks.providers.x.validator'],
    'missing secret' => [['providers' => ['x' => ['validator' => 'stripe']]], 'firefly.webhooks.providers.x.secret'],
    'bad id' => [['providers' => ['../x' => ['secret' => 's']]], 'provider id'],
    'bad algorithm' => [['providers' => ['x' => ['secret' => 's', 'algorithm' => 'md42']]], 'firefly.webhooks.providers.x.algorithm'],
    'bad encoding' => [['providers' => ['x' => ['secret' => 's', 'encoding' => 'hex64']]], 'firefly.webhooks.providers.x.encoding'],
    'unknown store' => [['idempotency' => ['store' => 'redis']], 'firefly.webhooks.idempotency.store'],
    'bad tolerance' => [['replay' => ['tolerance' => 'soon']], 'firefly.webhooks.replay.tolerance'],
    'empty body limit' => [['max-body-bytes' => 0], 'firefly.webhooks.max-body-bytes'],
]);
