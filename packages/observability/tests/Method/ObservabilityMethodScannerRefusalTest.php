<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;
use Firefly\Observability\Tests\Fixtures\AncestorFinalMethod\AncestorTimedService;
use Firefly\Observability\Tests\Fixtures\BeanWired\BeanWiredGateway;
use Firefly\Observability\Tests\Fixtures\InheritedBase\StripeGateway;

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

/*
 | …and neither is the stereotype asked of the SCANNED class alone. A metric written on a concrete base whose
 | stereotyped child is the bean DOES record — the child exposes the inherited method, so the child compiles its
 | own row and the child's proxy overrides the inherited body. Refusing that told a template-method author
 | something untrue about their own wiring (making the base `abstract`, the only change, compiled fine). The
 | base's OWN rows are dropped instead of refused: it is not a bean, so a row keyed by it would compile a proxy
 | nothing ever wraps, and the child's row already carries the meter.
 */

it('accepts a metric on a concrete base class whose post-processed child is in the same scan', function (): void {
    $rules = (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\InheritedBase' => __DIR__.'/../Fixtures/InheritedBase']);

    // Exactly one row, on the class that IS the bean — not two, and not a refusal.
    expect(array_map(fn (ObservabilityMethodDescriptor $d): string => $d->key(), $rules))->toBe([StripeGateway::class.'::charge']);
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

/*
 | The final-method refusal fires only where its own remedy exists for the person reading it. "Remove `final`
 | from the method" is not an instruction an author can follow about a base class they do not own — and the
 | framework's own AutoConfiguration::register() is final, so a #[Service] #[Timed] subclass of any such base
 | would otherwise hard-fail firefly:cache with an impossible remedy. A final method reached PURELY by the
 | class-level fan-out from an ANCESTOR is therefore skipped; one the class declares itself, or one carrying the
 | attribute by hand, is still refused (both above).
 */

it('skips an ancestor\'s final method the class-level fan-out reached, and times everything else it exposes', function (): void {
    $keys = array_map(
        fn (ObservabilityMethodDescriptor $d): string => $d->key(),
        (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\AncestorFinalMethod' => __DIR__.'/../Fixtures/AncestorFinalMethod']),
    );
    sort($keys);

    // `boot()` is the ancestor's final method: skipped, not refused. `inheritedStep()` is the ancestor's
    // NON-final one: timed, which is the inherited breadth of `getMethods(IS_PUBLIC)` made visible.
    expect($keys)->toBe([AncestorTimedService::class.'::inheritedStep', AncestorTimedService::class.'::own']);
});

it('still refuses a final method that carries the attribute itself, inherited or not', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\InheritedFinalAttribute' => __DIR__.'/../Fixtures/InheritedFinalAttribute']);
})->throws(ConfigurationException::class, 'SealedChildService::sealed cannot be recorded: the method is final');

/*
 | A `static` or `__`-prefixed method cannot be intercepted either way, but the two ways IN are not the same
 | thing. Reached by the class-level fan-out, skipping is right (the author wrote one attribute about the class,
 | not one about `__invoke`) — that is pinned in ObservabilityMethodScannerTest. Written BY HAND on the method,
 | a skip compiles the attribute into nothing at all: no descriptor, no exception, no warning, on the mainstream
 | single-action-service shape. That is the silent no-op this whole scan exists to refuse.
 */

it('refuses a metric attribute written by hand on __invoke(), rather than compiling it into nothing', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\MagicMethod' => __DIR__.'/../Fixtures/MagicMethod']);
})->throws(ConfigurationException::class, 'InvokableTimedService::__invoke cannot be recorded: the `__firefly*` members');

it('refuses a metric attribute written by hand on a public static method', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\StaticMethod' => __DIR__.'/../Fixtures/StaticMethod']);
})->throws(ConfigurationException::class, 'StaticTimedService::bulk cannot be recorded: a static call has no instance');
