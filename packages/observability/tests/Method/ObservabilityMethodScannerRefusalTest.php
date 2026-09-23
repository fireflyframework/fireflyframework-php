<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;
use Firefly\Observability\Tests\Fixtures\AncestorFinalMethod\AncestorTimedService;
use Firefly\Observability\Tests\Fixtures\BeanWired\BeanWiredGateway;
use Firefly\Observability\Tests\Fixtures\ClassLevelBase\BaseGateway as ClassLevelBaseGateway;
use Firefly\Observability\Tests\Fixtures\ClassLevelBase\StripeGateway as ClassLevelStripeGateway;
use Firefly\Observability\Tests\Fixtures\InheritedBase\StripeGateway;
use Firefly\Observability\Tests\Fixtures\SiblingSubclass\StripeGateway as SiblingStripeGateway;

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

/*
 | …but "the child already has its own row" is true of exactly ONE of the two ways an attribute reaches a
 | method, and dropping the base's rows per CLASS threw the other away in silence — no descriptor, no
 | exception, no warning, on a #[Timed] somebody wrote. `ReflectionMethod::getAttributes()` reads the DECLARING
 | class, so the accept above works; `ReflectionClass::getAttributes()` walks no parents, and an override
 | carries its own empty attribute list. The drop is therefore decided PER METHOD, against the rows the
 | post-processed subclasses really compiled, and the two shapes where the child compiles nothing are refused.
 */

it('refuses a CLASS-level metric on a concrete base, which its post-processed child does not inherit', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\ClassLevelBase' => __DIR__.'/../Fixtures/ClassLevelBase']);
})->throws(ConfigurationException::class, 'a class-level #[Timed]/#[Counted]/#[Observed] is NOT INHERITED');

it('names the base, the child and both remedies when a class-level metric is not inherited', function (): void {
    try {
        (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\ClassLevelBase' => __DIR__.'/../Fixtures/ClassLevelBase']);
        throw new RuntimeException('expected ConfigurationException, none thrown');
    } catch (ConfigurationException $e) {
        // The site is the ANNOTATED class, never the child: that is the file with the attribute in it.
        expect($e->getMessage())
            ->toContain(ClassLevelBaseGateway::class.'::charge')
            ->toContain('Move the class-level attribute onto '.ClassLevelStripeGateway::class)
            ->toContain('write it on charge() instead');
    }
});

it('refuses a CLASS-level metric on an ABSTRACT base, which no scan would otherwise reach at all', function (): void {
    // classes() keeps to instantiable classes, so this base is never walked in its own right: without the
    // ancestor check the attribute compiles into nothing with nothing said, one step further out of reach than
    // the concrete base above.
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\AbstractClassLevelBase' => __DIR__.'/../Fixtures/AbstractClassLevelBase']);
})->throws(ConfigurationException::class, 'the attribute is written at CLASS level on an ABSTRACT class');

it('refuses a metric on a base method the post-processed child overrides without repeating it', function (): void {
    (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\OverriddenBase' => __DIR__.'/../Fixtures/OverriddenBase']);
})->throws(ConfigurationException::class, 'OVERRIDES charge() without repeating the attribute');

/*
 | The other half of the per-method rule: a class is only refused for the rules it is RESPONSIBLE for. A second
 | subclass of an annotated base — a fake, a test double — compiles rows it never asked for, and refusing it
 | hard-failed `firefly:cache` naming a class whose author greps it for a metric attribute and finds none, with
 | the remedy "add a stereotype such as #[Service]" on a class they deliberately left unwired.
 */

it('drops, in silence, rows an unstereotyped sibling subclass only inherited, and keeps the bean\'s', function (): void {
    $rules = (new ObservabilityMethodScanner)->scan(['Firefly\\Observability\\Tests\\Fixtures\\SiblingSubclass' => __DIR__.'/../Fixtures/SiblingSubclass']);

    expect(array_map(fn (ObservabilityMethodDescriptor $d): string => $d->key(), $rules))->toBe([SiblingStripeGateway::class.'::charge']);
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
