<?php

declare(strict_types=1);

use Firefly\Callbacks\CallbacksSettings;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/**
 * @param  array<array-key, mixed>  $callbacks  a firefly.callbacks subtree, as a config file or a dataset spells it
 */
function w4CallbacksSettings(array $callbacks): CallbacksSettings
{
    return CallbacksSettings::fromConfig(new Config(new Repository(['firefly' => ['callbacks' => $callbacks]])));
}

it('has safe defaults: off, nothing subscribed, https only, three attempts, a breaker per endpoint', function () {
    $settings = w4CallbacksSettings([]);

    expect($settings)->toEqual(new CallbacksSettings)
        ->and($settings->enabled)->toBeFalse()
        ->and($settings->eventTypes)->toBe([])
        ->and($settings->requireHttps)->toBeTrue()
        ->and($settings->maxAttempts)->toBe(3)
        ->and($settings->breakerEnabled)->toBeTrue();
});

it('reads every key, durations and seeded subscriptions included', function () {
    $settings = w4CallbacksSettings([
        'enabled' => true,
        'event-types' => ['order.*'],
        'registry' => ['store' => 'database'],
        'log' => ['store' => 'database'],
        'connection' => 'pgsql',
        'retry' => ['max-attempts' => 5, 'initial-delay' => '250ms', 'multiplier' => 3, 'max-delay' => '10s'],
        'http' => ['timeout' => '2s', 'require-https' => false, 'allowed-hosts' => ['Example.com']],
        'circuit-breaker' => ['enabled' => false, 'failure-threshold' => 2, 'wait-duration-in-open' => '1m'],
        'signature' => ['header' => 'X-Sig', 'timestamp-header' => 'X-Ts'],
        'subscriptions' => [['url' => 'https://partner.example.com/hooks', 'event-types' => ['order.created'], 'secret' => 's']],
    ]);

    expect($settings->registryStore)->toBe('database')
        ->and($settings->connection)->toBe('pgsql')
        ->and([$settings->maxAttempts, $settings->initialDelay, $settings->multiplier, $settings->maxDelay])->toBe([5, 0.25, 3.0, 10.0])
        ->and([$settings->timeout, $settings->requireHttps, $settings->allowedHosts])->toBe([2.0, false, ['example.com']])
        ->and([$settings->breakerEnabled, $settings->failureThreshold, $settings->waitDurationInOpen])->toBe([false, 2, 60.0])
        ->and([$settings->signatureHeader, $settings->timestampHeader])->toBe(['X-Sig', 'X-Ts'])
        ->and($settings->subscriptions[0]->eventTypes)->toBe(['order.created'])
        ->and($settings->subscriptions[0]->endpoint())->toBe('https://partner.example.com:443');
});

it('refuses at boot what could never deliver', function (array $callbacks, string $message) {
    expect(fn () => w4CallbacksSettings($callbacks))->toThrow(ConfigurationException::class, $message);
})->with([
    'unknown registry' => [['registry' => ['store' => 'redis']], 'firefly.callbacks.registry.store'],
    'unknown log' => [['log' => ['store' => 'file']], 'firefly.callbacks.log.store'],
    'no attempts' => [['retry' => ['max-attempts' => 0]], 'firefly.callbacks.retry.max-attempts'],
    'event types not strings' => [['event-types' => [1]], 'firefly.callbacks.event-types'],
    'subscription without url' => [['subscriptions' => [['event-types' => ['a']]]], 'firefly.callbacks.subscriptions.0.url'],
    'subscription without event types' => [['subscriptions' => [['url' => 'https://x.example.com']]], 'firefly.callbacks.subscriptions.0.event-types'],
    'subscription id longer than its column' => [['subscriptions' => [['id' => 'partner-orders-production-eu-west-1a-2', 'url' => 'https://x.example.com', 'event-types' => ['a']]]], 'firefly.callbacks.subscriptions.0.id must be at most 36 characters'],
    'subscription id with unsafe characters' => [['subscriptions' => [['id' => 'orders/eu west', 'url' => 'https://x.example.com', 'event-types' => ['a']]]], 'firefly.callbacks.subscriptions.0.id'],
    'subscription id not a string' => [['subscriptions' => [['id' => 42, 'url' => 'https://x.example.com', 'event-types' => ['a']]]], 'firefly.callbacks.subscriptions.0.id'],
]);

it('accepts a seeded id exactly as wide as the id columns', function () {
    $id = str_repeat('a', CallbackSubscription::MAX_ID_LENGTH);
    $settings = w4CallbacksSettings(['subscriptions' => [['id' => $id, 'url' => 'https://x.example.com', 'event-types' => ['a']]]]);

    expect($settings->subscriptions[0]->id)->toBe($id);
});
