<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Rabbitmq\EdaRabbitmqServiceProvider;
use Firefly\Eda\Rabbitmq\RabbitMqEventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;

/**
 * THE WIRING, NOT THE CLASS — eda-postgres's OutboxTracingWiringTest for the RabbitMQ side.
 *
 * RabbitMqPublisherTracingTest constructs the publisher BY HAND with an explicit $tracing argument, which proves
 * publish() routes through the seam and nothing at all about the branch RabbitMqAutoConfiguration::eventPublisher()
 * takes. That branch is where `firefly.eda.tracing.brokers.enabled` is applied, and a compliance control — "keep
 * the in-process spans, put no trace identifier on a wire a third party reads" — is exactly the kind of thing that
 * has to be pinned rather than assumed: with nothing asserting it, deleting the BrokerTracing::resolve() call from
 * the bean and passing the raw $tracing through left the whole suite green, which is the precise regression the
 * one-place gate was written to prevent.
 *
 * The real boot pipeline is driven (bootFireflyApp over the committed manifests, provider=rabbitmq active) because
 * the bean's #[ConditionalOnProperty] gate and its argument list are the things under test. Nothing here opens a
 * connection: php-amqplib connects lazily, inside publish()/health(), never at construction.
 */
function rabbitWiringTracing(): EdaTracing
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

function bootRabbitPublisher(EdaTracing $tracing, ?bool $brokersEnabled = null): EventPublisher
{
    $eda = ['provider' => 'rabbitmq'];
    if ($brokersEnabled !== null) {
        $eda['tracing'] = ['brokers' => ['enabled' => $brokersEnabled]];
    }

    $context = bootFireflyApp(
        ['firefly' => ['eda' => $eda]],
        [EdaRabbitmqServiceProvider::class],
        [EdaTracing::class => $tracing],
    );

    /** @var EventPublisher $publisher */
    $publisher = $context->get(EventPublisher::class);

    return $publisher;
}

it('hands the auto-configured RabbitMQ publisher the EdaTracing the app bound', function () {
    $tracing = rabbitWiringTracing();

    $publisher = bootRabbitPublisher($tracing);

    // Read reflectively because `tracing` is a private readonly constructor property with no accessor, and which
    // EdaTracing actually reached the publisher is the whole claim.
    expect($publisher)->toBeInstanceOf(RabbitMqEventPublisher::class)
        ->and((new ReflectionProperty($publisher, 'tracing'))->getValue($publisher))->toBe($tracing);
});

it('downgrades the RabbitMQ publisher to the NoOp when firefly.eda.tracing.brokers.enabled is false', function () {
    $publisher = bootRabbitPublisher(rabbitWiringTracing(), brokersEnabled: false);

    expect((new ReflectionProperty($publisher, 'tracing'))->getValue($publisher))->toBeInstanceOf(NoOpEdaTracing::class);
});
