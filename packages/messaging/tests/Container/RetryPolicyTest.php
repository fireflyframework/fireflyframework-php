<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Messaging\Container\ExponentialBackOff;
use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Container\Seconds;
use Firefly\Messaging\Listener\MessageListenerDescriptor;
use Illuminate\Config\Repository;

it('backs off exponentially up to the cap, and linearly when a listener asks for it', function () {
    $exponential = new ExponentialBackOff(1.0, 2.0, 5.0);
    $linear = new LinearBackOff(0.5);

    expect(array_map($exponential->delayAfter(...), [1, 2, 3, 4]))->toBe([1.0, 2.0, 4.0, 5.0])
        ->and(array_map($linear->delayAfter(...), [1, 2, 3]))->toBe([0.5, 1.0, 1.5]);
});

it("defaults to PyFly's container policy: five attempts, 1s doubling, capped at 30s", function () {
    $policy = RetryPolicy::fromConfig(new Config(new Repository(['firefly' => []])));

    expect($policy->maxAttempts)->toBe(5)
        ->and($policy->backOff)->toEqual(new ExponentialBackOff(1.0, 2.0, 30.0));
});

it('reads the listener retry keys, durations included', function () {
    $policy = RetryPolicy::fromConfig(new Config(new Repository(['firefly' => ['messaging' => ['listener' => ['retry' => [
        'max-attempts' => 3, 'initial-delay' => '250ms', 'multiplier' => 3, 'max-delay' => '2s',
    ]]]]])));

    expect($policy->maxAttempts)->toBe(3)
        ->and($policy->backOff)->toEqual(new ExponentialBackOff(0.25, 3.0, 2.0));
});

it("lets a listener's own retries override the container default", function () {
    $options = ListenerOptions::fromDescriptor(new MessageListenerDescriptor('C', 'm', 'orders', null, 2, 0.1, 'orders.dead'));
    $policy = (new RetryPolicy)->withOptions($options);

    expect($options->deadLetterTopic)->toBe('orders.dead')
        ->and($policy->maxAttempts)->toBe(3)
        ->and($policy->backOff)->toEqual(new LinearBackOff(0.1))
        ->and((new RetryPolicy)->withOptions(ListenerOptions::fromDescriptor(new MessageListenerDescriptor('C', 'm', 'orders'))))->toEqual(new RetryPolicy);
});

it('refuses a policy that would never deliver, and a malformed duration', function () {
    expect(fn () => new RetryPolicy(0))->toThrow(ConfigurationException::class)
        ->and(fn () => Seconds::parse('soon', 1.0, 'firefly.messaging.listener.retry.initial-delay'))
        ->toThrow(ConfigurationException::class, 'firefly.messaging.listener.retry.initial-delay')
        ->and(Seconds::parse('1m', 0.0, 'k'))->toBe(60.0)
        ->and(Seconds::parse(null, 1.5, 'k'))->toBe(1.5);
});

it('refuses a multiplier that is not a number of at least 1', function (mixed $multiplier) {
    $config = new Config(new Repository(['firefly' => ['messaging' => ['listener' => ['retry' => ['multiplier' => $multiplier]]]]]));

    expect(fn () => RetryPolicy::fromConfig($config))->toThrow(ConfigurationException::class, 'firefly.messaging.listener.retry.multiplier');
})->with(['twice', 0.5, -2]);
