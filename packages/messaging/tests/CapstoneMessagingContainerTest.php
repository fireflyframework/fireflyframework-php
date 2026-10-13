<?php

declare(strict_types=1);

use Firefly\Messaging\Container\LinearBackOff;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Tests\Support\MessagingArtisan;
use Firefly\Messaging\Tests\Support\MessagingContainerCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;

uses(MessagingContainerCapstoneTestCase::class);

it('subscribes each #[MessageListener] with its own options and leaves retries to the container', function () {
    /** @var MessagingContainerCapstoneTestCase $this */
    $byTopic = [];
    foreach ($this->broker->subscriptions as $subscription) {
        $byTopic[$subscription['topic']] = $subscription['options'];
    }

    expect($byTopic['orders'])->toEqual(new ListenerOptions)
        ->and($byTopic['failing'])->toEqual(new ListenerOptions(3, new LinearBackOff(0.0), 'failing.DLT'));
});

it('consumes through php artisan firefly:messaging:consume and stops the broker', function () {
    /** @var MessagingContainerCapstoneTestCase $this */
    $this->broker->publish('orders', 'bytes-1');

    MessagingArtisan::outputContains($this->artisan('firefly:messaging:consume', ['--max-messages' => 1, '--poll-timeout' => 10]), 0, 'processed 1 message');

    expect($this->app()->make(ListenerSpy::class)->seen)->toBe(['orders'])
        ->and($this->broker->stopped)->toBeTrue()
        ->and($this->deadLetters->all())->toBe([]);
});

it('stops between deliveries on SIGTERM and still stops the broker', function () {
    /** @var MessagingContainerCapstoneTestCase $this */
    $broker = $this->broker;
    foreach (['bytes-1', 'bytes-2', 'bytes-3'] as $value) {
        $broker->publish('orders', $value);
    }
    $broker->onPoll = static function (): void {
        posix_kill(posix_getpid(), SIGTERM);
    };

    MessagingArtisan::outputContains($this->artisan('firefly:messaging:consume', ['--max-messages' => 3, '--poll-timeout' => 10]), 0, 'processed 1 message');

    expect($this->app()->make(ListenerSpy::class)->seen)->toBe(['orders'])
        ->and($this->broker->published)->toHaveCount(2)
        ->and($this->broker->stopped)->toBeTrue();
})->skip(! function_exists('pcntl_signal') || ! function_exists('posix_kill'), 'needs ext-pcntl and ext-posix');
