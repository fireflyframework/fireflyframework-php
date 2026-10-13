<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Messaging;

use Closure;
use Firefly\Eda\Kafka\RdKafkaConsumerClient;
use Firefly\Messaging\Container\DeadLetterHeaders;
use Firefly\Messaging\Container\DeliveryOutcome;
use Firefly\Messaging\Container\ListenerContainerBroker;
use Firefly\Messaging\Container\ListenerInvoker;
use Firefly\Messaging\Container\ListenerOptions;
use Firefly\Messaging\Container\RetryPolicy;
use Firefly\Messaging\Exception\MessagingException;
use Firefly\Messaging\Message;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * firefly/messaging on Kafka (firefly.messaging.provider=kafka) — a listener container:
 *
 *   * ONE CONSUMER PER CONSUMER GROUP, built on the first poll() and subscribed to every topic a listener of that
 *     group named (a listener without a group joins firefly.messaging.kafka.group-id). Kafka assigns a group's
 *     partitions across processes, which is the competing-consumers semantics the in-memory broker only imitates.
 *   * AT-LEAST-ONCE: a record is committed only after every listener of its topic either handled it or had it
 *     dead-lettered. A listener that throws is attempted again under the RetryPolicy (its own ListenerOptions
 *     first), then its record is produced to its own deadLetterTopic, or to `<topic><dlt-suffix>` (`.DLT`), with the
 *     diagnostic headers and the eda adapter's x-dlt-* provenance headers PyFly also writes. With dead letters off
 *     (firefly.messaging.listener.dead-letter.enabled=false) the record is logged and skipped — an explicit choice.
 *   * A dead letter that cannot be produced throws OUT of poll() with the offset NOT committed: the worker exits,
 *     its supervisor restarts it, and the record is consumed again — never silently lost.
 *   * publish() produces and flushes before returning, so a message published from a PHP-FPM request is on the
 *     broker when the request ends.
 */
final class KafkaMessageBroker implements ListenerContainerBroker
{
    /** @var array<string, array<string, list<array{handler: callable, options: ListenerOptions}>>> group => topic => listeners */
    private array $listeners = [];

    /** @var array<string, KafkaRecordClient> */
    private array $consumers = [];

    /**
     * @param  Closure(string): KafkaRecordClient  $consumerFor  builds the consumer of one group
     */
    public function __construct(
        private readonly Closure $consumerFor,
        private readonly KafkaRecordClient $producer,
        private readonly RetryPolicy $policy,
        private readonly ListenerInvoker $invoker,
        private readonly string $defaultGroup = 'firefly',
        private readonly string $deadLetterSuffix = '.DLT',
        private readonly bool $deadLetterEnabled = true,
        private readonly int $flushTimeoutMs = 10000,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void
    {
        $this->producer->produce($topic, $value, $key, $headers);
        $this->producer->flush($this->flushTimeoutMs);
    }

    public function subscribe(string $topic, callable $handler, ?string $group = null): void
    {
        $this->subscribeListener($topic, $handler, $group, new ListenerOptions);
    }

    public function subscribeListener(string $topic, callable $handler, ?string $group, ListenerOptions $options): void
    {
        $group ??= $this->defaultGroup;
        if (isset($this->consumers[$group])) {
            throw new MessagingException("The Kafka consumer of group [{$group}] is already polling; subscribe every listener before the first poll().");
        }

        $this->listeners[$group][$topic][] = ['handler' => $handler, 'options' => $options];
    }

    public function poll(int $timeoutMs): bool
    {
        if ($this->listeners === []) {
            return false;
        }

        $slice = max(1, intdiv($timeoutMs, count($this->listeners)));

        foreach (array_keys($this->listeners) as $group) {
            $group = (string) $group;
            $consumer = $this->consumer($group);
            $record = $consumer->consume($slice);
            if ($record === null) {
                continue;
            }

            $this->dispatch($group, $record);
            $consumer->commit($record);

            return true;
        }

        return false;
    }

    public function start(): void {}

    public function stop(): void
    {
        foreach ($this->consumers as $consumer) {
            $consumer->close();
        }
        $this->consumers = [];
        $this->producer->flush($this->flushTimeoutMs);
    }

    private function consumer(string $group): KafkaRecordClient
    {
        if (! isset($this->consumers[$group])) {
            $client = ($this->consumerFor)($group);
            $client->subscribe(array_map('strval', array_keys($this->listeners[$group])));
            $this->consumers[$group] = $client;
        }

        return $this->consumers[$group];
    }

    private function dispatch(string $group, KafkaRecord $record): void
    {
        $message = new Message($record->topic, $record->value, $record->key, $record->headers);

        foreach ($this->listeners[$group][$record->topic] ?? [] as $listener) {
            $outcome = $this->invoker->invoke($listener['handler'], $message, $this->policy->withOptions($listener['options']));
            if (! $outcome->handled) {
                $this->deadLetter($record, $message, $outcome, $listener['options']);
            }
        }
    }

    private function deadLetter(KafkaRecord $record, Message $message, DeliveryOutcome $outcome, ListenerOptions $options): void
    {
        $target = $options->deadLetterTopic ?? ($this->deadLetterEnabled ? $record->topic.$this->deadLetterSuffix : null);
        $cause = $outcome->failure?->getMessage() ?? '';

        if ($target === null) {
            $this->logger?->error("Kafka record {$record->topic}@{$record->offset} failed {$outcome->attempts} attempt(s) and dead letters are off; skipping it: {$cause}");

            return;
        }

        $headers = array_merge($record->headers, DeadLetterHeaders::for($message, $outcome), [
            RdKafkaConsumerClient::REASON_HEADER => DeadLetterHeaders::reason($outcome),
            RdKafkaConsumerClient::SOURCE_TOPIC_HEADER => $record->topic,
            RdKafkaConsumerClient::SOURCE_OFFSET_HEADER => (string) $record->offset,
        ]);

        try {
            $this->producer->produce($target, $record->value, $record->key, $headers);
            $this->producer->flush($this->flushTimeoutMs);
        } catch (Throwable $e) {
            throw new MessagingException("Kafka record {$record->topic}@{$record->offset} could not be dead-lettered to [{$target}]; its offset is left uncommitted so it is consumed again.", 'MESSAGING_DEAD_LETTER_FAILED', $e);
        }

        $this->logger?->error("Kafka record {$record->topic}@{$record->offset} failed {$outcome->attempts} attempt(s); dead-lettered to [{$target}]: {$cause}");
    }
}
