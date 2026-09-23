<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;
use Firefly\Observability\Tests\Fixtures\BeanWired\BeanWiredGateway;

it('refuses a metric attribute on a class with no stereotype', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\Unstereotyped' => __DIR__.'/../Fixtures/Unstereotyped']);
})->throws(ConfigurationException::class, 'carries no #[Component]-family stereotype');

/*
 | …but the stereotype is not the whole rule, so the refusal above is not a stereotype test. The
 | bean-post-processor chain is installed for a #[Component]-family class AND for the declared return type of
 | a #[Bean] factory method, and TransactionalBeanPostProcessor keys on the compiled PLAN — which is why
 | #[Transactional] on an unstereotyped, #[Bean]-wired class works today. Refusing that shape would have
 | failed `firefly:cache` while telling its author something untrue about their own wiring.
 */

it('accepts a metric on an unstereotyped class a #[Bean] method in the scanned roots returns', function (): void {
    $rules = (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\BeanWired' => __DIR__.'/../Fixtures/BeanWired']);

    expect(array_map(fn (ObservabilityMethodDescriptor $d): string => $d->key(), $rules))->toBe([BeanWiredGateway::class.'::charge']);
});

it('refuses a metric attribute on a final class', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\FinalService' => __DIR__.'/../Fixtures/FinalService']);
})->throws(ConfigurationException::class, 'is final and a proxy must extend it');

/*
 | A `final` METHOD is the same fatal one level down, and it used to be reached BY THE PROXY rather than by
 | this scan: the generated class overrides every planned method, so `require` of it dies with "Cannot
 | override final method" and names neither the attribute nor the class-level rule that reached the method.
 | Both ways in are refused — the attribute written on the method, and the class-level attribute that fanned
 | onto it — because skipping the fanned-onto one would leave exactly the flat line this scan exists to
 | prevent.
 */

it('refuses a metric attribute on a final method, which no proxy could override', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\FinalMethod' => __DIR__.'/../Fixtures/FinalMethod']);
})->throws(ConfigurationException::class, 'FinalMethodService::place cannot be recorded: the method is final');

it('refuses a final method a class-level attribute fanned onto, rather than skipping it in silence', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\ClassLevelFinalMethod' => __DIR__.'/../Fixtures/ClassLevelFinalMethod']);
})->throws(ConfigurationException::class, 'InheritedFinalMethodService::sealed cannot be recorded: the method is final');

it('refuses #[Timed(percentiles:)] and names the key that does publish percentiles', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\Percentiles' => __DIR__.'/../Fixtures/Percentiles']);
})->throws(ConfigurationException::class, 'firefly.observability.metrics.distribution.per-meter');
