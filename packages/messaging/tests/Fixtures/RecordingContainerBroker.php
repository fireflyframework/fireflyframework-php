<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Fixtures;

use Closure;
use Firefly\Messaging\Container\ListenerContainerBroker;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Message;

/**
 * A container broker without a broker: records what the wiring pass subscribed, and delivers what was published
 * on the next poll() through the real ListenerInvoker — enough to prove the wiring and the consume command.
 */
final class RecordingContainerBroker implements ListenerContainerBroker
{
    /** @var list<array{topic: string, group: string|null, options: ListenerOptions}> */
    public array $subscriptions = [];

    /** @var list<Message> */
    public array $published = [];

    public bool $stopped = false;

    /** @var (Closure(): void)|null runs at the start of every poll(), e.g. to deliver a signal mid-run */
    public ?Closure $onPoll = null;

    /** @var array<string, list<array{handler: callable, options: ListenerOptions}>> */
    private array $listeners = [];

    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
    {
        $this->published[] = new Message($topic, $value, $key, $headers);
    }

    public function subscribe(string $topic, callable $handler, ?string $group = null): void
    {
        $this->subscribeListener($topic, $handler, $group, new ListenerOptions);
    }

    public function subscribeListener(string $topic, callable $handler, ?string $group, ListenerOptions $options): void
    {
        $this->subscriptions[] = ['topic' => $topic, 'group' => $group, 'options' => $options];
        $this->listeners[$topic][] = ['handler' => $handler, 'options' => $options];
    }

    public function poll(int $timeoutMs): bool
    {
        if ($this->onPoll !== null) {
            ($this->onPoll)();
        }

        $message = array_shift($this->published);
        if ($message === null) {
            return false;
        }

        $invoker = new ListenerInvoker(static function (float $seconds): void {});
        foreach ($this->listeners[$message->topic] ?? [] as $listener) {
            $invoker->invoke($listener['handler'], $message, (new RetryPolicy(1))->withOptions($listener['options']));
        }

        return true;
    }

    public function start(): void {}

    public function stop(): void
    {
        $this->stopped = true;
    }
}
