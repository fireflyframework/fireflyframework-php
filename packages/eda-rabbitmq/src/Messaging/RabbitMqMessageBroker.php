<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Messaging;

use Firefly\Messaging\Container\DeadLetterHeaders;
use Firefly\Messaging\Container\DeliveryOutcome;
use Firefly\Messaging\Container\ListenerContainerBroker;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;
use Psr\Log\LoggerInterface;

/**
 * firefly/messaging on RabbitMQ (firefly.messaging.provider=rabbitmq) — a listener container on PyFly's topology:
 *
 *   * a durable DIRECT exchange (firefly.messaging.rabbitmq.exchange); a topic is a routing key;
 *   * one durable queue per subscription — the listener's group, else `firefly.<topic>` — bound to its topic and
 *     declared with x-dead-letter-exchange = the DLX (`<exchange>.dlx` unless configured) and
 *     x-dead-letter-routing-key = the queue, so a rejected message lands in `<queue>.dlq`, bound on the DLX;
 *   * manual acknowledgement with a bounded prefetch.
 *
 * A delivery is acknowledged after its listeners handled it. A listener that throws is attempted again under the
 * RetryPolicy (its own ListenerOptions first). After the last attempt: a listener deadLetterTopic is honoured by
 * republishing to the exchange under that routing key, with the diagnostic headers, and acknowledging; otherwise
 * the delivery is REJECTED without requeue and the broker's dead-letter exchange moves it to `<queue>.dlq` with its
 * x-death history — RabbitMQ's own DLX, the eda adapter's choice too. With dead letters off it is acknowledged and
 * logged. A republish that fails throws out of poll() unacknowledged: the channel dies with the worker and the
 * broker redelivers.
 *
 * One queue serves one topic; two listeners of one group on one topic share the queue (and its DLX route), which is
 * why one failing listener rejects the delivery for both — give listeners their own group when that matters.
 * The message key travels in the `x-message-key` header (AMQP has no key).
 */
final class RabbitMqMessageBroker implements ListenerContainerBroker
{
    public const string KEY_HEADER = 'x-message-key';

    /** @var array<string, array{topic: string, listeners: list<array{handler: callable, options: ListenerOptions}>}> */
    private array $queues = [];

    private bool $exchangeDeclared = false;

    private bool $consuming = false;

    /** @var list<AmqpDelivery> */
    private array $pending = [];

    public function __construct(
        private readonly AmqpMessagingChannel $channel,
        private readonly RetryPolicy $policy,
        private readonly ListenerInvoker $invoker,
        private readonly string $exchange = 'firefly.messages',
        private readonly string $deadLetterExchange = '',
        private readonly int $prefetch = 10,
        private readonly bool $deadLetterEnabled = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function queueNameOf(string $topic, ?string $group): string
    {
        return $group ?? 'firefly.'.$topic;
    }

    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
    {
        $this->declareExchange();
        $this->channel->publish($this->exchange, $topic, $value, $key === null ? $headers : array_merge($headers, [self::KEY_HEADER => $key]));
    }

    public function subscribe(string $topic, callable $handler, ?string $group = null): void
    {
        $this->subscribeListener($topic, $handler, $group, new ListenerOptions);
    }

    public function subscribeListener(string $topic, callable $handler, ?string $group, ListenerOptions $options): void
    {
        if ($this->consuming) {
            throw new MessagingException('The RabbitMQ messaging broker is already consuming; subscribe every listener before the first poll().');
        }

        $queue = self::queueNameOf($topic, $group);
        if (isset($this->queues[$queue]) && $this->queues[$queue]['topic'] !== $topic) {
            throw new MessagingException("Queue [{$queue}] already consumes topic [{$this->queues[$queue]['topic']}]; a queue serves one topic, so give [{$topic}] its own group.");
        }

        $this->queues[$queue]['topic'] = $topic;
        $this->queues[$queue]['listeners'][] = ['handler' => $handler, 'options' => $options];
    }

    public function poll(int $timeoutMs): bool
    {
        if ($this->queues === []) {
            return false;
        }

        $this->startConsuming();

        if ($this->pending === [] && ! $this->channel->wait($timeoutMs / 1000)) {
            return false;
        }

        $delivery = array_shift($this->pending);
        if ($delivery === null) {
            return false;
        }

        $this->dispatch($delivery);

        return true;
    }

    public function start(): void {}

    public function stop(): void
    {
        $this->channel->close();
        $this->consuming = false;
        $this->exchangeDeclared = false;
        $this->pending = [];
    }

    private function deadLetterExchange(): string
    {
        return $this->deadLetterExchange !== '' ? $this->deadLetterExchange : $this->exchange.'.dlx';
    }

    private function declareExchange(): void
    {
        if (! $this->exchangeDeclared) {
            $this->channel->declareExchange($this->exchange, 'direct');
            $this->exchangeDeclared = true;
        }
    }

    private function startConsuming(): void
    {
        if ($this->consuming) {
            return;
        }

        $this->declareExchange();
        $dlx = $this->deadLetterExchange();
        $this->channel->declareExchange($dlx, 'direct');
        $this->channel->qos($this->prefetch);

        foreach ($this->queues as $queue => $subscription) {
            $queue = (string) $queue;
            $this->channel->declareQueue($queue, ['x-dead-letter-exchange' => $dlx, 'x-dead-letter-routing-key' => $queue]);
            $this->channel->bindQueue($queue, $this->exchange, $subscription['topic']);
            $this->channel->declareQueue($queue.'.dlq', []);
            $this->channel->bindQueue($queue.'.dlq', $dlx, $queue);
            $this->channel->consume($queue, function (AmqpDelivery $delivery): void {
                $this->pending[] = $delivery;
            });
        }

        $this->consuming = true;
    }

    private function dispatch(AmqpDelivery $delivery): void
    {
        $subscription = $this->queues[$delivery->queue] ?? null;
        if ($subscription === null) {
            $this->channel->nack($delivery->deliveryTag, false);

            return;
        }

        $headers = $delivery->headers;
        $key = $headers[self::KEY_HEADER] ?? null;
        unset($headers[self::KEY_HEADER]);
        $message = new Message($subscription['topic'], $delivery->body, $key, $headers);

        $exhausted = null;
        foreach ($subscription['listeners'] as $listener) {
            $outcome = $this->invoker->invoke($listener['handler'], $message, $this->policy->withOptions($listener['options']));
            if ($outcome->handled) {
                continue;
            }

            if ($listener['options']->deadLetterTopic !== null) {
                $this->republish($listener['options']->deadLetterTopic, $message, $outcome);

                continue;
            }

            $exhausted ??= $outcome;
        }

        if ($exhausted === null) {
            $this->channel->ack($delivery->deliveryTag);

            return;
        }

        $cause = $exhausted->failure?->getMessage() ?? '';
        if ($this->deadLetterEnabled) {
            $this->logger?->error("RabbitMQ message on [{$delivery->queue}] failed {$exhausted->attempts} attempt(s); rejected to [{$delivery->queue}.dlq]: {$cause}");
            $this->channel->nack($delivery->deliveryTag, false);

            return;
        }

        $this->logger?->error("RabbitMQ message on [{$delivery->queue}] failed {$exhausted->attempts} attempt(s) and dead letters are off; acknowledged and skipped: {$cause}");
        $this->channel->ack($delivery->deliveryTag);
    }

    private function republish(string $topic, Message $message, DeliveryOutcome $outcome): void
    {
        $headers = array_merge($message->headers, DeadLetterHeaders::for($message, $outcome));
        if ($message->key !== null) {
            $headers[self::KEY_HEADER] = $message->key;
        }

        $this->channel->publish($this->exchange, $topic, $message->value, $headers);
        $this->logger?->error("RabbitMQ message on [{$message->topic}] failed {$outcome->attempts} attempt(s); republished to [{$topic}].");
    }
}
