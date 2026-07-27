<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Boot\FireflyKernel;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Kafka\EdaKafkaServiceProvider;
use Firefly\Eda\Kafka\KafkaHealthIndicator;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Regression gate for the SP-4 "surprise /actuator/health 503" precedent (RabbitMqHealthIndicatorGatingTest /
 * PostgresHealthIndicatorGatingTest): installing firefly/eda-kafka must stay fully INERT — KafkaHealthIndicator
 * AND the EventPublisher/EventConsumer beans must NOT be registered/active — unless firefly.eda.provider=kafka is
 * the active provider.
 *
 * KAFKA-SPECIFIC SUBTLETY (unlike RabbitMQ, whose connection factory only connects lazily inside health()):
 * KafkaAutoConfiguration::eventPublisher() and ::eventConsumer() both THROW a clear RuntimeException at
 * bean-RESOLUTION time when provider=kafka and ext-rdkafka is absent — the WANTED fail-fast (a clear boot error,
 * never a silent no-op). On this machine (no ext-rdkafka), EagerSingletonsPass (BootPhase::EagerSingletons, 900 —
 * resolves every non-#[Lazy] Singleton bean, #[Order]-sorted, straight from the manifest) reaches eventPublisher()
 * (declared first) during `$app->boot()`, and the throw surfaces there, aborting the boot pipeline before
 * eventConsumer() is ever resolved. So this file proves two DISTINCT, genuine things — not one vacuous "it didn't
 * crash" check:
 *
 *   (A) FAIL-FAST: booting under provider=kafka with the ext absent throws that RuntimeException. Because
 *       ConditionPassTwoPass (600 — decides which beans/components SURVIVE gating) runs strictly BEFORE
 *       EagerSingletonsPass (900 — where the throw fires; see FireflyServiceProvider's booting()/booted()
 *       split — 600 is drained by the booting() closure, 900 by the booted() closure, both invoked in
 *       sequence from the SAME $app->boot() call), the BeanDefinitionRegistry already reflects the real
 *       gating outcome by the time the throw happens. BeanDefinitionRegistry::containsType() on the SAME
 *       kernel the failed boot ran against then proves EventPublisher/EventConsumer/KafkaHealthIndicator are
 *       GENUINELY registered under provider=kafka — not merely inferred from the throw alone (EventConsumer in
 *       particular is NEVER reached by EagerSingletonsPass before the throw, so this is the only way to prove its
 *       definition survived gating).
 *   (B) INERTNESS: booting under provider=memory (or provider absent) completes with NO throw, and neither
 *       KafkaHealthIndicator nor EventPublisher nor EventConsumer is bound in the resulting ApplicationContext —
 *       proving the Kafka beans are fully gated OFF for a non-kafka provider.
 *
 * Built by hand (not via firefly/testing's bootFireflyApp()) because the (A) case needs a live reference to
 * $app that SURVIVES the boot()-time exception — bootFireflyApp()'s local $app would be unreachable once it
 * propagates a throw.
 */
function buildKafkaApplication(?string $provider): Application
{
    $eda = $provider === null ? [] : ['provider' => $provider];

    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['eda' => $eda]]));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new EdaKafkaServiceProvider($app));

    return $app;
}

it('(A) refuses to boot under provider=kafka with a clear ext-rdkafka error when the extension is absent', function () {
    if (extension_loaded('rdkafka')) {
        $this->markTestSkipped('rdkafka present — the fail-fast guard path is not exercised.');
    }

    $app = buildKafkaApplication('kafka');

    $caught = null;
    try {
        $app->boot();
    } catch (RuntimeException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught?->getMessage())->toContain('ext-rdkafka');

    /** @var FireflyKernel $kernel */
    $kernel = $app->make(FireflyKernel::class);

    // ConditionPassTwoPass already ran (600 < 900) before the throw above, so the registry genuinely
    // reflects the real gating decision: these beans are ACTIVE under provider=kafka, not merely assumed.
    expect($kernel->context()->definitions->containsType(EventPublisher::class))->toBeTrue()
        ->and($kernel->context()->definitions->containsType(EventConsumer::class))->toBeTrue()
        ->and($kernel->context()->definitions->containsType(KafkaHealthIndicator::class))->toBeTrue();
});

it('(B) does not register KafkaHealthIndicator, EventPublisher, or EventConsumer when provider = memory', function () {
    $app = buildKafkaApplication('memory');
    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->has(KafkaHealthIndicator::class))->toBeFalse()
        ->and($context->has(EventPublisher::class))->toBeFalse()
        ->and($context->has(EventConsumer::class))->toBeFalse();
});

it('(B) does not register KafkaHealthIndicator, EventPublisher, or EventConsumer when firefly.eda.provider is absent', function () {
    $app = buildKafkaApplication(null);
    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->has(KafkaHealthIndicator::class))->toBeFalse()
        ->and($context->has(EventPublisher::class))->toBeFalse()
        ->and($context->has(EventConsumer::class))->toBeFalse();
});
