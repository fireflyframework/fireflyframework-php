<?php

declare(strict_types=1);

use Firefly\Actuator\Health\HealthIndicator;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Eda\EventPublisher;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Testing\Double\FakeHealthIndicator;
use Firefly\Testing\Double\RecordingCommandBus;
use Firefly\Testing\Double\RecordingEventPublisher;
use Firefly\Testing\Tests\Capstone\Fixtures\OrderPlacer;
use Firefly\Testing\Tests\Capstone\Fixtures\PlaceOrder;

it('composes a REAL Firefly boot with the harness doubles + expectations end-to-end', function () {
    $bus = new RecordingCommandBus;
    $publisher = new RecordingEventPublisher;
    $registry = new SimpleMeterRegistry;
    $health = new FakeHealthIndicator; // defaults UP

    // A real boot: FireflyAutoConfigureServiceProvider scans the fixtures; the container wires OrderPlacer
    // to the doubles we bound as its ports. Nothing is hand-wired.
    $context = bootFireflyApp(
        config: ['firefly' => ['scan' => ['paths' => [
            'Firefly\\Testing\\Tests\\Capstone\\Fixtures\\' => __DIR__.'/Fixtures',
        ]]]],
        bindings: [
            CommandBus::class => $bus,
            EventPublisher::class => $publisher,
            MetricsRecorder::class => $registry,
            MeterRegistry::class => $registry,
            HealthIndicator::class => $health,
        ],
    );

    // Resolve the real scanned service from the real context and exercise it.
    /** @var OrderPlacer $orderPlacer */
    $orderPlacer = $context->get(OrderPlacer::class);
    $orderPlacer->place('o-1');

    // Proof the REAL #[Service] scan registered OrderPlacer as a bean: the scan registers it as a
    // singleton, so two resolutions return the SAME instance. Plain container reflection-autowiring
    // (which would happen even if scanning were disabled, since the ctor deps are all bound) returns a
    // DISTINCT instance each time — so this identity check fails if the scan didn't run.
    expect($context->get(OrderPlacer::class))->toBe($context->get(OrderPlacer::class));

    // Each custom expectation is kept on its own expect() statement: chaining them via ->and() degrades
    // the ignorable `method.notFound` PHPStan error into the unignorable `method.nonObject` one, because
    // ->and() re-wraps the return value and loses the runtime-registered-expectation type information.
    // @phpstan-ignore method.notFound
    expect($bus)->toHaveHandledCommand(PlaceOrder::class);
    // @phpstan-ignore method.notFound
    expect($publisher)->toHavePublished('order.placed', payloadContains: ['id' => 'o-1']);
    // @phpstan-ignore method.notFound
    expect($registry)->toHaveRecordedMetric('orders.placed', tags: ['region' => 'eu']);

    /** @var HealthIndicator $healthIndicator */
    $healthIndicator = $context->get(HealthIndicator::class);
    // @phpstan-ignore method.notFound
    expect($healthIndicator->health())->toBeUp();
});
