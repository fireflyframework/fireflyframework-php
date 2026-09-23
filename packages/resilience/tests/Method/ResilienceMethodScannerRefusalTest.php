<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Scanner\ResilienceMethodScanner;
use Firefly\Resilience\Tests\Fixtures\AncestorFinalMethod\AncestorRetryGateway;
use Firefly\Resilience\Tests\Fixtures\BeanWired\BeanWiredGateway;
use Firefly\Resilience\Tests\Fixtures\ClassLevelBase\BaseGateway as ClassLevelBaseGateway;
use Firefly\Resilience\Tests\Fixtures\ClassLevelBase\StripeGateway as ClassLevelStripeGateway;
use Firefly\Resilience\Tests\Fixtures\InheritedBase\StripeGateway as InheritedStripeGateway;
use Firefly\Resilience\Tests\Fixtures\SiblingSubclass\StripeGateway as SiblingStripeGateway;

/**
 * The refusals, each against its OWN fixture directory so no case depends on scan order — the sibling of
 * ObservabilityMethodScannerRefusalTest, case for case, because this scanner is a faithful port of that one
 * and every branch they share must be held down in both packages rather than in the one that happened to be
 * written first.
 *
 * @return array<string, string>
 */
function resilienceOffenderPsr4(string $dir): array
{
    return ['Firefly\\Resilience\\Tests\\Fixtures\\'.$dir => __DIR__.'/../Fixtures/'.$dir];
}

/**
 * The keys one scan compiled, sorted — the shape every accept below asserts against, because "which rows"
 * is the whole answer and a single-offset assertion would miss a row that should have been dropped.
 *
 * @return list<string>
 */
function resilienceKeys(string $dir): array
{
    $keys = array_map(
        fn (ResilienceMethodDescriptor $d): string => $d->key(),
        (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4($dir)),
    );
    sort($keys);

    return $keys;
}

it('refuses resilience attributes on an unstereotyped class', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('UnstereotypedPayments'));
})->throws(ConfigurationException::class, 'carries no #[Component]-family stereotype');

/*
 | …but the stereotype is not the whole rule, so the refusal above is not a stereotype test. The
 | bean-post-processor chain is installed for a #[Component]-family class AND for the declared return type of
 | a #[Bean] factory method, which is why #[Transactional] on an unstereotyped, #[Bean]-wired class works
 | today. Refusing that shape would fail `firefly:cache` while telling its author something untrue about
 | their own wiring.
 */

it('accepts a guard on an unstereotyped class a #[Bean] method in the scanned roots returns', function (): void {
    expect(resilienceKeys('BeanWired'))->toBe([BeanWiredGateway::class.'::charge']);
});

/*
 | …and neither is the stereotype asked of the SCANNED class alone. A guard written on a concrete base whose
 | stereotyped child is the bean DOES run — the child exposes the inherited method, so the child compiles its
 | own row and the child's proxy overrides the inherited body. The base's OWN rows are dropped rather than
 | refused: the base is not a bean, so a row keyed by it would compile a proxy nothing ever wraps.
 */

it('accepts a guard on a concrete base class whose post-processed child is in the same scan', function (): void {
    // Exactly one row, on the class that IS the bean — not two, and not a refusal.
    expect(resilienceKeys('InheritedBase'))->toBe([InheritedStripeGateway::class.'::charge']);
});

/*
 | …but "the child already has its own row" is true of exactly ONE of the two ways an attribute reaches a
 | method, and dropping the base's rows per CLASS threw the other away in silence — no descriptor, no
 | exception, no warning, on a #[Retry] somebody wrote. `ReflectionMethod::getAttributes()` reads the
 | DECLARING class, so the accept above works; `ReflectionClass::getAttributes()` walks no parents, and an
 | override carries its own empty attribute list. The drop is therefore decided PER METHOD, and the two
 | shapes where the child compiles nothing are refused.
 */

it('refuses a CLASS-level guard on a concrete base, which its post-processed child does not inherit', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('ClassLevelBase'));
})->throws(ConfigurationException::class, 'is NOT INHERITED');

it('names the base, the child and both remedies when a class-level guard is not inherited', function (): void {
    try {
        (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('ClassLevelBase'));
        throw new RuntimeException('expected ConfigurationException, none thrown');
    } catch (ConfigurationException $e) {
        // The site is the ANNOTATED class, never the child: that is the file with the attribute in it.
        expect($e->getMessage())
            ->toContain(ClassLevelBaseGateway::class.'::charge')
            ->toContain('Move the class-level attribute onto '.ClassLevelStripeGateway::class)
            ->toContain('write it on charge() instead');
    }
});

it('refuses a CLASS-level guard on an ABSTRACT base, which no scan would otherwise reach at all', function (): void {
    // classes() keeps to instantiable classes, so this base is never walked in its own right: without the
    // ancestor check the attribute compiles into nothing with nothing said, one step further out of reach
    // than the concrete base above.
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('AbstractClassLevelBase'));
})->throws(ConfigurationException::class, 'the attribute is written at CLASS level on an ABSTRACT class');

it('refuses a guard on a base method the post-processed child overrides without repeating it', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('OverriddenBase'));
})->throws(ConfigurationException::class, 'OVERRIDES charge() without repeating the attribute');

/*
 | The other half of the per-method rule: a class is only refused for the rules it is RESPONSIBLE for. A
 | second subclass of an annotated base — a fake, a test double — compiles rows it never asked for, and
 | refusing it would hard-fail `firefly:cache` naming a class whose author greps it for a resilience
 | attribute and finds none, with the remedy "add a stereotype such as #[Service]" on a class they
 | deliberately left unwired.
 */

it('drops, in silence, rows an unstereotyped sibling subclass only inherited, and keeps the bean\'s', function (): void {
    expect(resilienceKeys('SiblingSubclass'))->toBe([SiblingStripeGateway::class.'::charge']);
});

it('refuses resilience attributes on a final class', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('FinalPayments'));
})->throws(ConfigurationException::class, 'is final and a proxy must extend it');

/*
 | A `final` METHOD is the same fatal one level down, and without this refusal it is reached BY THE PROXY
 | rather than by the scan: the generated class overrides every planned method, so `require` of it dies with
 | "Cannot override final method" and names neither the attribute nor the class-level rule that reached the
 | method. Both ways in are refused — the attribute written on the method, and the class-level attribute that
 | fanned onto a method the class DECLARES — because skipping the fanned-onto one would leave exactly one
 | unguarded method in an otherwise guarded class.
 */

it('refuses a guard on a final method, which no proxy could override', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('FinalMethod'));
})->throws(ConfigurationException::class, 'FinalMethodGateway::charge cannot be applied: the method is final');

it('refuses a final method a class-level guard fanned onto, rather than skipping it in silence', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('ClassLevelFinalMethod'));
})->throws(ConfigurationException::class, 'SealedMethodGateway::sealed cannot be applied: the method is final');

/*
 | …but the refusal fires only where its own remedy exists for the person reading it. "Remove `final` from
 | the method" is not an instruction an author can follow about a base class they do not own — and the
 | framework's own AutoConfiguration::register() is final, so a #[Service] #[Retry] subclass of any such base
 | would otherwise hard-fail firefly:cache with an impossible remedy.
 */

it('skips an ancestor\'s final method the class-level fan-out reached, and guards everything else it exposes', function (): void {
    // `boot()` is the ancestor's final method: skipped, not refused. `inheritedStep()` is the ancestor's
    // NON-final one: guarded, which is the inherited breadth of `getMethods(IS_PUBLIC)` made visible.
    expect(resilienceKeys('AncestorFinalMethod'))
        ->toBe([AncestorRetryGateway::class.'::inheritedStep', AncestorRetryGateway::class.'::own']);
});

/*
 | A `static` or `__`-prefixed method cannot be intercepted either way, but the two ways IN are not the same
 | thing. Reached by the class-level fan-out, skipping is right (the author wrote one attribute about the
 | class, not one about `__invoke`) — that is pinned in ResilienceMethodScannerTest. Written BY HAND on the
 | method, a skip compiles the attribute into nothing at all, on the mainstream single-action-service shape.
 */

it('refuses a guard written by hand on __invoke(), rather than compiling it into nothing', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('MagicMethod'));
})->throws(ConfigurationException::class, 'InvokableRetryGateway::__invoke cannot be applied: the `__firefly*` members');

it('refuses a guard written by hand on a public static method', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('StaticMethod'));
})->throws(ConfigurationException::class, 'StaticRetryGateway::chargeAll cannot be applied: a static call has no instance');

/*
 | The five #[Fallback] refusals, which are the reason this scanner exists at all rather than being the
 | observability one with different attribute names. Each of them is a `Call to …` fatal, or an unbounded
 | recursion, raised from INSIDE the catch block that was absorbing the outage the fallback was written for —
 | the single worst moment to find out — and each is provable by reflection without running anything.
 */

it('refuses a #[Fallback] with nothing to fall back from', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('LonelyFallback'));
})->throws(ConfigurationException::class, 'carries no other resilience attribute');

it('refuses a #[Fallback] naming the guarded method itself, which recovers by recursing', function (): void {
    // Every other check here waves it through: the method exists, and it receives its own arguments by
    // construction. Run, it calls itself back through the proxy until the stack ends — and an open breaker
    // does not bound it, because the breaker's refusal is caught by the same fallback.
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('SelfFallback'));
})->throws(ConfigurationException::class, 'names the guarded method itself');

it('refuses a #[Fallback] naming a method the class does not have', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('MissingFallback'));
})->throws(ConfigurationException::class, 'names fallback method');

it('refuses a #[Fallback] the interceptor could not call, because the method is not public', function (): void {
    // `hasMethod()` answers true for a `protected` and a `private` method alike, so the existence check above
    // accepts both — and the interceptor invokes the recovery as `[$bean, $method](...)` from its own scope,
    // which is a call from outside the class. `isPublic()` is the one question that tells them apart.
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('NonPublicFallback'));
})->throws(ConfigurationException::class, 'which is protected. The interceptor calls the recovery on the bean from OUTSIDE the class');

it('refuses a #[Fallback] whose signature cannot receive the guarded call', function (): void {
    (new ResilienceMethodScanner)->scan(resilienceOffenderPsr4('IncompatibleFallback'));
})->throws(ConfigurationException::class, 'cannot receive the guarded call');
