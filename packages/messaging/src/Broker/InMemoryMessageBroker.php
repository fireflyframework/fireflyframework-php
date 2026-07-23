<?php

declare(strict_types=1);

namespace Firefly\Messaging\Broker;

use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;

/**
 * The default MessageBrokerPort adapter: an in-process broker with consumer-group semantics. A groupless
 * subscriber receives EVERY message on its topic (broadcast); subscribers sharing a named group receive messages
 * round-robin (one per publish), modelling a real broker's competing-consumers within a group. Requires start()
 * before publish() (pyfly messaging/adapters/memory.py parity). Zero external services — the skeleton default.
 * Handlers arrive already retry/DLQ-wrapped from the wiring pass.
 */
final class InMemoryMessageBroker implements MessageBrokerPort
{
    private bool $started = false;

    /** @var array<string, list<array{handler: callable, group: ?string}>> */
    private array $subscribers = [];

    /** @var array<string, array<string, int>> round-robin cursor per topic+group */
    private array $cursors = [];

    public function subscribe(string $topic, callable $handler, ?string $group = null): void
    {
        $this->subscribers[$topic][] = ['handler' => $handler, 'group' => $group];
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
    {
        if (! $this->started) {
            throw new MessagingException("Cannot publish to '{$topic}': the in-memory broker has not been started. Call start() first.");
        }

        $message = new Message($topic, $value, $key, $headers);

        /** @var array<string, list<callable>> $groups */
        $groups = [];

        foreach ($this->subscribers[$topic] ?? [] as $subscriber) {
            if ($subscriber['group'] === null) {
                ($subscriber['handler'])($message); // broadcast
            } else {
                $groups[$subscriber['group']][] = $subscriber['handler'];
            }
        }

        foreach ($groups as $group => $handlers) {
            $cursor = $this->cursors[$topic][$group] ?? 0;
            $handlers[$cursor % count($handlers)]($message);
            $this->cursors[$topic][$group] = $cursor + 1;
        }
    }

    public function start(): void
    {
        $this->started = true;
    }

    public function stop(): void
    {
        $this->started = false;
    }
}
