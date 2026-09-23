<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Kafka\EdaKafkaServiceProvider;
use Firefly\Eda\Kafka\KafkaEventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use PHPUnit\Framework\Assert;

/**
 * THE WIRING, NOT THE CLASS — RabbitMqTracingWiringTest's sibling, and eda-postgres's OutboxTracingWiringTest for
 * the Kafka side.
 *
 * KafkaPublisherTracingTest constructs the publisher BY HAND with an explicit $tracing argument, which proves
 * publish() routes through the seam and nothing about the branch KafkaAutoConfiguration::eventPublisher() takes.
 * That branch is where `firefly.eda.tracing.brokers.enabled` is applied, and a compliance control — "keep the
 * in-process spans, put no trace identifier on a wire a third party reads" — has to be pinned rather than assumed:
 * with nothing asserting it, deleting the BrokerTracing::resolve() call from the bean and passing the raw $tracing
 * through left the whole suite green.
 *
 * EXT-RDKAFKA. Unlike RabbitMQ, this bean FAILS FAST at resolution time when the extension is absent (a deliberate
 * boot error, pinned by KafkaAutoConfigurationGatingTest case (A)), so the publisher genuinely cannot be built on a
 * machine without it and these two cases skip there. Nothing below talks to a broker: KafkaProducerFactory only
 * builds an RdKafka\Producer inside producer(), which nothing here calls.
 */
function kafkaWiringTracing(): EdaTracing
{
    return new class implements EdaTracing
    {
        public function tracePublish(string $destination, string $eventType, array $headers, callable $send): void
        {
            $send([...$headers, 'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
        }

        public function traceConsume(EventEnvelope $envelope, callable $deliver): void
        {
            $deliver($envelope);
        }
    };
}

function bootKafkaPublisher(EdaTracing $tracing, ?bool $brokersEnabled = null): EventPublisher
{
    $eda = ['provider' => 'kafka'];
    if ($brokersEnabled !== null) {
        $eda['tracing'] = ['brokers' => ['enabled' => $brokersEnabled]];
    }

    $context = bootFireflyApp(
        ['firefly' => ['eda' => $eda]],
        [EdaKafkaServiceProvider::class],
        [EdaTracing::class => $tracing],
    );

    /** @var EventPublisher $publisher */
    $publisher = $context->get(EventPublisher::class);

    return $publisher;
}

it('hands the auto-configured Kafka publisher the EdaTracing the app bound', function () {
    if (! extension_loaded('rdkafka')) {
        Assert::markTestSkipped('ext-rdkafka is absent — the publisher bean fails fast before it can be built.');
    }

    $tracing = kafkaWiringTracing();

    $publisher = bootKafkaPublisher($tracing);

    // Read reflectively because `tracing` is a private readonly constructor property with no accessor, and which
    // EdaTracing actually reached the publisher is the whole claim.
    expect($publisher)->toBeInstanceOf(KafkaEventPublisher::class)
        ->and((new ReflectionProperty($publisher, 'tracing'))->getValue($publisher))->toBe($tracing);
});

it('downgrades the Kafka publisher to the NoOp when firefly.eda.tracing.brokers.enabled is false', function () {
    if (! extension_loaded('rdkafka')) {
        Assert::markTestSkipped('ext-rdkafka is absent — the publisher bean fails fast before it can be built.');
    }

    $publisher = bootKafkaPublisher(kafkaWiringTracing(), brokersEnabled: false);

    expect((new ReflectionProperty($publisher, 'tracing'))->getValue($publisher))->toBeInstanceOf(NoOpEdaTracing::class);
});
