<?php

declare(strict_types=1);

namespace Firefly\Resilience\Scanner;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Method\Bulkhead;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\RateLimiter;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Method\Retry;
use Firefly\Resilience\Method\TimeLimiter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The SCAN-TIME half of the resilience advice, and the only reflecting file this package adds — the
 * ObservabilityMethodScanner / MethodSecurityScanner idiom, deliberately: walk the app's PSR-4 roots, read
 * the effective #[Bulkhead], #[TimeLimiter], #[RateLimiter], #[CircuitBreaker], #[Retry] and #[Fallback] on
 * each public method (a method-level attribute REPLACES a class-level one of the same kind, Spring and
 * Resilience4j semantics), normalise each to a flat row naming only the INSTANCE, and refuse — loudly, here,
 * where a person is running `firefly:cache` and can read the message — anything that would compile and then
 * be honoured by nothing.
 *
 * THE ROW HOLDS NAMES, NOT POLICY. `firefly.resilience.*` keeps the thresholds and the timeouts, and
 * ResilienceRegistry resolves them at call time, so a plan compiled today still obeys a breaker an operator
 * widens tomorrow. The compiled artifact records which patterns guard which method, nothing more.
 *
 * THE CLASS-LEVEL FAN-OUT REACHES INHERITED METHODS. A class-level attribute applies to every public
 * INSTANCE method the class EXPOSES, which is `getMethods(IS_PUBLIC)` — the ones it declares and the ones it
 * inherits alike. That is the right surface (the generated proxy overrides an inherited method as readily as
 * a declared one, so the guard does run) but it is a wider surface than "the methods in this file", and the
 * refusals below are shaped around the difference. #[Fallback] is the one attribute with no class-level
 * form: a recovery method is a statement about ONE signature, and fanning one name across every public
 * method of a class could not be right for more than one of them.
 *
 * WHAT IS REFUSED, AND WHY EACH ONE IS A REFUSAL RATHER THAN A SKIP:
 *
 *   - A class NOTHING POST-PROCESSES is never wrapped, so no proxy runs the guard. An unenforced #[Retry] is
 *     worse than no #[Retry]: the class reads as guarded, the review passes, and the first outage finds out.
 *     "Nothing post-processes it" is asked of the wiring rather than of the stereotype alone, because the
 *     stereotype is not the whole rule: RegisterBeanPostProcessorsPass installs the chain for a
 *     #[Component]-family class AND for the output of a #[Bean] factory method (keyed on the method's
 *     declared return type), which is why #[Transactional] on an unstereotyped, #[Bean]-wired class works
 *     today — packages/data's CapstoneTransactionalIntegrationTest watches exactly that proxy roll back. A
 *     stereotype-only refusal would reject that wiring while telling its author something untrue about it,
 *     so this one fires only when the scanned roots show NEITHER shape, and its message says which two it
 *     looked for.
 *   - …and it does not fire for a CONCRETE BASE CLASS whose post-processed child COMPILES THE SAME ROW.
 *     Writing the attribute on a template-method base and stereotyping the leaf is a mainstream shape, and
 *     the guard does run there — but only through the one door PHP leaves open, so the drop is decided PER
 *     METHOD against the rows the post-processed subclasses really compiled, never per class. That door is
 *     the METHOD-level attribute on a method the child does not override: `ReflectionMethod::getAttributes()`
 *     reads the DECLARING class, so the child sees it, compiles its own row for it and the child's proxy
 *     overrides the inherited body. The base's copy is then dropped rather than refused — the base is not a
 *     bean, so a row keyed by it would compile a proxy nothing ever wraps. The two shapes where the child
 *     compiles NOTHING are refused like any other unenforceable rule, because PHP inherits neither:
 *       * a CLASS-level attribute on the base — `ReflectionClass::getAttributes()` does not walk the parents,
 *         so the child returns [] for it and re-derives nothing. Refused at the base; and when that base is
 *         ABSTRACT, refused from the first concrete descendant scanned, because `classes()` never walks an
 *         abstract class in its own right and nothing else in the scan would ever mention it;
 *       * a METHOD-level attribute on a method the child OVERRIDES — an override carries its own, empty,
 *         attribute list.
 *   - …and a class is only ever refused for the rules it is RESPONSIBLE for. A rule that reached an
 *     unstereotyped class purely by inheritance — the attribute is on an ancestor, the class declares neither
 *     it nor the method — is dropped in silence: a second, hand-written subclass of an annotated base is not
 *     a site whose author can act on "add a stereotype such as #[Service]", and the annotated ancestor is
 *     where the refusal, if one is owed, already fires.
 *   - A class the advice must proxy while being `final` cannot be extended. Refused, same reason as the first.
 *   - A `final` METHOD cannot be overridden, and the generated proxy overrides every planned method. Without
 *     a refusal the plan compiles and the `require` of the generated class fatals with "Cannot override final
 *     method", naming neither the attribute nor the class-level rule that reached the method. The refusal
 *     fires only where its own remedy is available to the person reading it: the attribute was written on the
 *     method, or the class DECLARES the final method. A `final` method reached purely by the fan-out from an
 *     ANCESTOR is skipped in silence instead — "remove `final` from the method" is not an instruction an
 *     author can follow about a base class they do not own.
 *   - A `static` or `__`-prefixed method carrying an attribute of its OWN. Neither can be intercepted: a
 *     static call has no instance for a proxy to wrap, and the `__firefly*` members the generated proxy
 *     declares make the magic methods its own. Reached by the class-level fan-out these are skipped in
 *     silence; written EXPLICITLY on `__invoke()` — the single-action-service shape — or on a `public
 *     static`, they are the exact "compiles and is then honoured by nothing" this scan exists to refuse.
 *   - The six #[Fallback] refusals — five about the recovery METHOD and one about the `on:` LIST — see
 *     assertFallback(), which is the reason this scanner exists at all rather than being a copy of the
 *     observability one with different attribute names.
 *
 * Runs at cache time (and once per process on an uncached dev boot, through the AdviceSource). Production
 * loads the compiled plan.
 *
 * @phpstan-import-type ResilienceMethodRow from ResilienceMethodDescriptor
 */
final class ResilienceMethodScanner
{
    /**
     * @param  array<string, string>  $psr4  namespace-prefix => absolute directory
     * @return list<ResilienceMethodDescriptor>
     */
    public function scan(array $psr4): array
    {
        $classes = $this->classes($psr4);
        $factoryProduced = $this->beanFactoryTypes($classes);

        // FIRST PASS — compile every class's rows, and remember which of them the class must ANSWER for.
        // Nothing is dropped or refused for being unenforceable yet: whether a base's row may be dropped is a
        // question about the rows its post-processed subclasses compiled, and those are not known until the
        // whole scan has been compiled.
        /** @var array<class-string, list<ResilienceMethodDescriptor>> $compiled */
        $compiled = [];
        /** @var array<class-string, array<string, true>> $responsible */
        $responsible = [];

        foreach ($classes as $class) {
            [$classRules, $classResponsible] = $this->compile($class);

            if ($classRules !== []) {
                $compiled[$class] = $classRules;
                $responsible[$class] = $classResponsible;
            }
        }

        // SECOND PASS — keep, drop or refuse, with the whole scan in view.
        $rules = [];

        foreach ($compiled as $class => $classRules) {
            $reflection = new ReflectionClass($class);

            if ($this->postProcessed($reflection, $factoryProduced)) {
                // The class IS the enforcement point: its rows are the ones a proxy will apply, wherever the
                // attribute that produced them was written.
                $this->refuseUnproxyableClass($reflection);

                $rules = [...$rules, ...$classRules];

                continue;
            }

            $subclasses = $this->postProcessedSubclasses($class, $classes, $factoryProduced);
            $covered = $this->coveredMethods($subclasses, $compiled);

            foreach ($classRules as $rule) {
                if (! isset($responsible[$class][$rule->method]) || isset($covered[$rule->method])) {
                    // Somebody else's rule, or one a post-processed subclass really re-derived: dropping it
                    // here loses nothing, and refusing it would name a class whose author wrote no attribute.
                    continue;
                }

                $this->refuseUnenforceable($reflection, $rule, $subclasses);
            }
        }

        return $rules;
    }

    /**
     * The rows the proxy plan enforces — here, all of them: every rule this scanner accepts is one only a
     * proxy can apply, so unlike security's selection there is nothing to filter out.
     *
     * @param  array<string, string>  $psr4
     * @return array<class-string, array<string, ResilienceMethodRow>>
     */
    public function scanProxyAdvice(array $psr4): array
    {
        $advice = [];

        foreach ($this->scan($psr4) as $rule) {
            /** @var class-string $class */
            $class = $rule->class;
            $advice[$class][$rule->method] = $rule->toArray();
        }

        foreach ($advice as $class => $methods) {
            ksort($methods);
            $advice[$class] = $methods;
        }
        ksort($advice);

        return $advice;
    }

    /**
     * One class's rows, and the subset of them the class is RESPONSIBLE for — the two answers the second pass
     * needs about it. Every refusal that is a property of the SITE alone (an uninterceptable method carrying
     * an attribute by hand, a `final` method, a #[Fallback] that cannot work) fires here; the ones that
     * depend on what else was scanned wait for the caller.
     *
     * "Responsible" is ownership of the attribute, not of the method: a class attribute is always the class's
     * own (PHP does not inherit them), and a method attribute belongs to the class that DECLARES the method.
     * A row that reached a class purely by inheritance is the annotated ancestor's to answer for.
     *
     * @param  class-string  $class
     * @return array{0: list<ResilienceMethodDescriptor>, 1: array<string, true>}
     */
    private function compile(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $classBulkhead = $this->first($reflection->getAttributes(Bulkhead::class));
        $classTimeLimiter = $this->first($reflection->getAttributes(TimeLimiter::class));
        $classRateLimiter = $this->first($reflection->getAttributes(RateLimiter::class));
        $classCircuitBreaker = $this->first($reflection->getAttributes(CircuitBreaker::class));
        $classRetry = $this->first($reflection->getAttributes(Retry::class));

        $classAnnotated = $classBulkhead !== null || $classTimeLimiter !== null || $classRateLimiter !== null
            || $classCircuitBreaker !== null || $classRetry !== null;

        $this->refuseAbstractAncestorRule($reflection);

        /** @var list<ResilienceMethodDescriptor> $classRules */
        $classRules = [];
        /** @var array<string, true> $responsible */
        $responsible = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $site = $class.'::'.$method->getName();

            // The method's OWN attributes are read FIRST, before any skip: what a skip may silently drop
            // is a rule the class-level attribute fanned onto this method, never one somebody wrote here.
            $ownBulkhead = $this->first($method->getAttributes(Bulkhead::class));
            $ownTimeLimiter = $this->first($method->getAttributes(TimeLimiter::class));
            $ownRateLimiter = $this->first($method->getAttributes(RateLimiter::class));
            $ownCircuitBreaker = $this->first($method->getAttributes(CircuitBreaker::class));
            $ownRetry = $this->first($method->getAttributes(Retry::class));
            $fallback = $this->first($method->getAttributes(Fallback::class));

            $annotated = $ownBulkhead !== null || $ownTimeLimiter !== null || $ownRateLimiter !== null
                || $ownCircuitBreaker !== null || $ownRetry !== null || $fallback !== null;

            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                if ($annotated) {
                    throw new ConfigurationException($this->uninterceptableMessage($site, $method));
                }

                continue;
            }

            $bulkhead = $ownBulkhead ?? $classBulkhead;
            $timeLimiter = $ownTimeLimiter ?? $classTimeLimiter;
            $rateLimiter = $ownRateLimiter ?? $classRateLimiter;
            $circuitBreaker = $ownCircuitBreaker ?? $classCircuitBreaker;
            $retry = $ownRetry ?? $classRetry;

            $hasPattern = $bulkhead !== null || $timeLimiter !== null || $rateLimiter !== null
                || $circuitBreaker !== null || $retry !== null;

            if (! $hasPattern && $fallback === null) {
                continue;
            }

            if ($method->isFinal()) {
                if (! $annotated && $method->getDeclaringClass()->getName() !== $class) {
                    // Fanned onto an ANCESTOR's final method: the remedy the message below offers does
                    // not exist for its reader, and the method is not part of this class's own surface.
                    continue;
                }

                throw new ConfigurationException(
                    "Resilience attributes on {$site} cannot be applied: the method is final and a proxy must "
                    .'override it. Remove `final` from the method (a class-level #[Retry]/#[CircuitBreaker]/'
                    .'#[RateLimiter]/#[Bulkhead]/#[TimeLimiter] applies to every public method, this one included), '
                    .'or call the pattern through ResilienceRegistry at the call site.'
                );
            }

            // assertFallback() refuses, and then HANDS BACK the one fact about the recovery that is not a
            // name: whether its last parameter accepts the caught Throwable. It is answered here, where the
            // recovery's ReflectionMethod is already open for the arity proof, so the interceptor that
            // appends the cause never has to reflect — see ResilienceMethodDescriptor's own paragraph on it.
            $fallbackAcceptsThrowable = $fallback !== null
                && $this->assertFallback($reflection, $method, $fallback, $hasPattern);

            $classRules[] = new ResilienceMethodDescriptor(
                $class,
                $method->getName(),
                $bulkhead?->name,
                $timeLimiter?->name,
                $rateLimiter?->name,
                $circuitBreaker?->name,
                $retry?->name,
                $fallback?->method,
                $fallback === null ? [] : $fallback->on,
                $fallbackAcceptsThrowable,
            );

            // …and who answers for it. A class attribute is always this class's own; a method attribute is the
            // DECLARING class's. A row that reached here through neither is one somebody wrote about an
            // ancestor, and refusing this class for it would name a file with no attribute in it.
            if ($classAnnotated || $method->getDeclaringClass()->getName() === $class) {
                $responsible[$method->getName()] = true;
            }
        }

        return [$classRules, $responsible];
    }

    /**
     * The SIX refusals that make #[Fallback] a compile-time contract rather than a runtime hope — five about
     * the recovery METHOD and one about the `on:` LIST — and the one FACT the scan hands back about it.
     *
     *   - A #[Fallback] with no other resilience attribute on the method guards nothing: the method would be
     *     proxied only to install a try/catch, which is a `try` the author can write themselves and which
     *     the reader of the class would have no way to see. Refused, with the sentence that says so.
     *   - The named method must not be the GUARDED METHOD ITSELF. Every other check here waves that one
     *     through — it exists by construction, and its signature receives its own arguments by construction —
     *     and at runtime it is the one shape that never terminates: the recovery calls the method back
     *     through the proxy, which re-enters this advice, fails, recovers, and recurses until the stack ends.
     *     A breaker does not bound it either; an OPEN breaker's refusal is a Throwable like any other, caught
     *     by the same fallback that calls the method again.
     *   - The named method must EXIST on the class. Reflection can prove that, so a typo must never reach
     *     production as a `Call to undefined method` raised from inside the catch block that was handling
     *     the outage.
     *   - The named method must be PUBLIC. The interceptor invokes the recovery as `[$bean, $method](...)`
     *     from its own scope — a call from OUTSIDE the class, whatever the proxy's own relationship to it —
     *     so a `protected` or `private` recovery fatals with `Error: Call to protected method` raised, again,
     *     from inside the catch that was absorbing the outage. `hasMethod()` answers true for both, which is
     *     why the check above is not enough on its own; `isPublic()` proves it without running anything.
     *   - The named method must be able to RECEIVE the guarded call: its required-parameter count cannot
     *     exceed the guarded method's parameter count, PLUS ONE only when the recovery's own last parameter
     *     accepts a Throwable — because that is the single condition under which the interceptor appends the
     *     cause, so an unconditional `+ 1` would wave through a recovery that then fatals with
     *     `ArgumentCountError` from inside the very catch this refusal exists to keep quiet. Anything looser
     *     than a required-parameter count cannot be proven without running it — a union type, a variadic —
     *     and is left to PHP.
     *   - Every entry of `on:` must be a LOADABLE THROWABLE. `$cause instanceof [a name nothing declares]` is
     *     false without autoloading and without erroring, and `instanceof stdClass` is false for everything a
     *     `catch` can ever hold, so a typo or a moved exception compiles verbatim into the row and the
     *     fallback silently never fires — the same "compiles and is then honoured by nothing" as a misspelt
     *     method name, one field to the right. `class_exists()`/`interface_exists()` and `is_a(…, true)`
     *     prove both halves, one entry at a time, in assertRecoverable().
     *
     * The order matters, and it is the order above: "carries no other resilience attribute" is asked first,
     * because a lonely #[Fallback] is wrong whatever it names and answering it with a paragraph about
     * parameter counts would send its author to fix the wrong thing; the self-reference is asked next,
     * because a method is always its own compatible signature and every later check would pass; existence
     * precedes visibility, which precedes arity, because each one is the premise of the next; and the `on:`
     * list is asked last, because a list narrowing a recovery that cannot be called at all is the second
     * thing its author needs to hear.
     *
     * THE RETURN VALUE is whether the recovery's LAST parameter accepts the Throwable — the fact the
     * interceptor needs in order to decide whether to append the cause to the original arguments. It is
     * answered here, from the ReflectionMethod the arity proof already opened, and compiled into the row; see
     * ResilienceMethodDescriptor for why that belongs in the plan rather than in a call-time reflection.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return bool whether the recovery's last parameter accepts the caught Throwable
     */
    private function assertFallback(ReflectionClass $reflection, ReflectionMethod $guarded, Fallback $fallback, bool $hasPattern): bool
    {
        $site = $reflection->getName().'::'.$guarded->getName();

        if (! $hasPattern) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} carries no other resilience attribute to fall back from. Add the pattern it "
                .'recovers (#[Retry], #[CircuitBreaker], #[RateLimiter], #[Bulkhead] or #[TimeLimiter]), or write the '
                .'try/catch in the method, where a reader of the class can see it.'
            );
        }

        if ($fallback->method === $guarded->getName()) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} names the guarded method itself. The recovery is called on the bean, which "
                .'is the proxy, so it re-enters this advice, fails again and recovers again — unbounded recursion '
                .'that ends in a stack overflow rather than in a degraded answer, and an open circuit breaker does '
                .'not bound it (its refusal is caught by the same fallback). Name a DIFFERENT method that returns '
                .'the degraded answer.'
            );
        }

        if (! $reflection->hasMethod($fallback->method)) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} names fallback method [{$fallback->method}], which ".$reflection->getName()
                .' does not declare. A fallback that does not exist fails inside the handler for the outage it was '
                .'meant to absorb, so it is refused here instead.'
            );
        }

        $recovery = $reflection->getMethod($fallback->method);

        if (! $recovery->isPublic()) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} names [{$fallback->method}], which is "
                .($recovery->isPrivate() ? 'private' : 'protected').'. The interceptor calls the recovery on the '
                .'bean from OUTSIDE the class, so a non-public method fatals with `Call to '
                .($recovery->isPrivate() ? 'private' : 'protected').' method` inside the catch that was handling the '
                .'outage — the one moment it must not. Make the fallback public, or call the pattern through '
                .'ResilienceRegistry at the call site.'
            );
        }

        $acceptsThrowable = $this->acceptsThrowable($recovery);
        $capacity = $guarded->getNumberOfParameters() + ($acceptsThrowable ? 1 : 0);

        if ($recovery->getNumberOfRequiredParameters() > $capacity) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} names [{$fallback->method}], which cannot receive the guarded call: it requires "
                .$recovery->getNumberOfRequiredParameters().' parameters and the call can supply at most '.$capacity
                .' (the guarded method\'s '.$guarded->getNumberOfParameters().' arguments'
                .($acceptsThrowable
                    ? ', plus the Throwable its last parameter accepts'
                    : ' — the interceptor appends the Throwable only when the fallback\'s LAST parameter accepts one, '
                        .'and this one\'s does not')
                .'). Give the fallback the guarded signature, with defaults for anything it does not need.'
            );
        }

        foreach ($fallback->on as $entry) {
            $this->assertRecoverable($entry, $site);
        }

        return $acceptsThrowable;
    }

    /**
     * ONE entry of `on:`, proved to be something a `catch` can actually hold.
     *
     * The parameter is a plain `string` on purpose. #[Fallback] declares `list<class-string<Throwable>>` and
     * that is the right contract for an author's editor, but an attribute argument is USER INPUT reaching
     * this scanner from an application that may never have run a static analyser — the docblock is a claim
     * about the list, not a guarantee, and this method is where the claim is made true. Narrowing it back to
     * `class-string<Throwable>` here would only prove the claim to itself.
     *
     * Two sentences rather than one, because the two failures have different remedies and the same symptom:
     * `$cause instanceof` a name nothing declares is FALSE without autoloading and without erroring, and
     * `instanceof stdClass` is false for everything a `catch` can hold — either way the narrowed list matches
     * nothing, the fallback never fires, and the outage propagates as though no #[Fallback] had been written.
     */
    private function assertRecoverable(string $entry, string $site): void
    {
        if (! class_exists($entry) && ! interface_exists($entry)) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} narrows `on:` to [{$entry}], which is not a class or an interface this "
                .'application can load. `$cause instanceof` a name nothing declares is FALSE without autoloading and '
                .'without erroring, so the entry compiles into the row verbatim and then matches nothing: the fallback '
                .'never fires and the outage propagates as though no #[Fallback] had been written. Correct the name, '
                .'or import the class the guarded call really throws.'
            );
        }

        if (! is_a($entry, Throwable::class, true)) {
            throw new ConfigurationException(
                "#[Fallback] on {$site} narrows `on:` to [{$entry}], which is not a Throwable. Nothing a `catch` can "
                .'ever hold is an instance of it, so the entry matches nothing and the fallback never fires for it. '
                .'Name an exception class or interface — the default, Throwable, recovers everything.'
            );
        }
    }

    /**
     * Whether a method's LAST parameter accepts a Throwable — the rule #[Fallback] states in its own
     * docblock, asked once at scan time and compiled into the row, so the interceptor decides whether to
     * append the cause by reading a boolean rather than by reflecting on every recovery.
     *
     * It answers the ARITY proof and the compiled flag with one implementation on purpose: the two disagreeing
     * is precisely the defect where a recovery with one extra non-Throwable parameter is waved through and
     * then fatals with `ArgumentCountError` inside the catch. A union type (`Throwable|string`) is not a
     * ReflectionNamedType and falls to false, which is the conservative answer: the cause is not appended, and
     * the arity proof treats the parameter as one the call cannot fill.
     */
    private function acceptsThrowable(ReflectionMethod $method): bool
    {
        $parameters = $method->getParameters();

        if ($parameters === []) {
            return false;
        }

        $type = $parameters[count($parameters) - 1]->getType();

        return $type instanceof ReflectionNamedType && is_a($type->getName(), Throwable::class, true);
    }

    /**
     * The two shapes a proxy can never route, told apart so the message says which one the reader hit. Only
     * ever reached for a method carrying an attribute of its own — the class-level fan-out skips both in
     * silence, because a rule written about the class is not a rule written about `__invoke()`.
     */
    private function uninterceptableMessage(string $site, ReflectionMethod $method): string
    {
        $why = $method->isStatic()
            ? 'a static call has no instance for a proxy to wrap, so the advice chain never sees it'
            : 'the `__firefly*` members the generated proxy declares make the magic methods its own, so a '
                .'`__`-prefixed method is never routed through the advice chain';

        return "Resilience attributes on {$site} cannot be applied: {$why}. Move the attribute to a public instance "
            .'method — a single-action service can keep `__invoke()` as a one-line delegate to a `handle()` that '
            .'carries the guards — or call the pattern through ResilienceRegistry at the call site.';
    }

    /**
     * The POST-PROCESSED SUBCLASSES of one class in the same scan — the template-method leaves that may be
     * carrying its rules, and the only classes whose own rows can make a drop safe. Asked only of a class
     * NOTHING post-processes: a base that is itself a bean keeps its rows, subclass or no subclass.
     *
     * @param  class-string  $class
     * @param  list<class-string>  $classes
     * @param  array<string, true>  $factoryProduced
     * @return list<class-string>
     */
    private function postProcessedSubclasses(string $class, array $classes, array $factoryProduced): array
    {
        $subclasses = [];

        foreach ($classes as $candidate) {
            if ($candidate === $class || ! is_subclass_of($candidate, $class)) {
                continue;
            }

            if ($this->postProcessed(new ReflectionClass($candidate), $factoryProduced)) {
                $subclasses[] = $candidate;
            }
        }

        return $subclasses;
    }

    /**
     * The method names those subclasses REALLY COMPILED A ROW FOR — the per-method premise the drop rests on,
     * read off the first pass rather than re-derived from the shape of the hierarchy.
     *
     * Asking it per CLASS ("a post-processed subclass exists, therefore the child already has its own row,
     * therefore nothing is lost") is true for exactly one of the two ways an attribute reaches a method.
     * `ReflectionMethod::getAttributes()` reads the DECLARING class, so a METHOD-level attribute on a method
     * the child does not override IS visible through the child. `ReflectionClass::getAttributes()` walks no
     * parents, so a CLASS-level attribute on the base is invisible to the child; and an override carries its
     * own, empty, attribute list. In those two shapes the child compiles nothing, and a per-class drop threw
     * the author's #[Retry] away in silence.
     *
     * @param  list<class-string>  $subclasses
     * @param  array<class-string, list<ResilienceMethodDescriptor>>  $compiled
     * @return array<string, true>
     */
    private function coveredMethods(array $subclasses, array $compiled): array
    {
        $covered = [];

        foreach ($subclasses as $subclass) {
            foreach ($compiled[$subclass] ?? [] as $rule) {
                $covered[$rule->method] = true;
            }
        }

        return $covered;
    }

    /**
     * The one wiring question the refusals ask: does the bean-post-processor chain reach an instance of this
     * class at all? Either of the two shapes RegisterBeanPostProcessorsPass installs an extender for answers
     * yes — a #[Component]-family stereotype, or a #[Bean] factory method in the scanned roots declaring it.
     *
     * @param  ReflectionClass<object>  $reflection
     * @param  array<string, true>  $factoryProduced
     */
    private function postProcessed(ReflectionClass $reflection, array $factoryProduced): bool
    {
        return $reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || isset($factoryProduced[$reflection->getName()]);
    }

    /**
     * A class the bean-post-processor chain DOES reach, but which a proxy cannot extend. Separate from the
     * per-rule refusal below because it is a property of the class alone: every row on it is unenforceable,
     * and which row the message named would be arbitrary.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function refuseUnproxyableClass(ReflectionClass $reflection): void
    {
        if (! $reflection->isFinal()) {
            return;
        }

        throw new ConfigurationException(
            "Resilience attributes on {$reflection->getName()} cannot be applied: the class is final and a proxy "
            .'must extend it. Remove `final`, or call the pattern through ResilienceRegistry at the call site.'
        );
    }

    /**
     * ONE rule, on a class nothing post-processes, that no post-processed subclass re-derived either — so
     * there is no seam left for it anywhere in the scan. The message is chosen from the three ways that
     * happens, because each has a different remedy and only one of them is "add a stereotype":
     *
     *   - no post-processed subclass at all: the original wiring refusal, unchanged;
     *   - the attribute is at CLASS level and a post-processed subclass exists: PHP does not inherit class
     *     attributes, so the subclass sees nothing to compile. Move the attribute down, or onto the method;
     *   - the attribute is on a METHOD a post-processed subclass OVERRIDES: PHP does not inherit method
     *     attributes across an override either. Repeat it on the override.
     *
     * Reached only for a rule the class is RESPONSIBLE for, so every message names a class whose author can
     * open the file and find the attribute the message is about.
     *
     * @param  ReflectionClass<object>  $reflection
     * @param  list<class-string>  $subclasses  the post-processed subclasses in the same scan
     */
    private function refuseUnenforceable(ReflectionClass $reflection, ResilienceMethodDescriptor $rule, array $subclasses): void
    {
        $site = $rule->key();

        if ($subclasses === []) {
            throw new ConfigurationException(
                "Resilience attributes on {$site} cannot be applied: the class carries no #[Component]-family "
                .'stereotype, no #[Bean] method in the scanned roots returns it and no post-processed subclass of it '
                .'was scanned, so nothing post-processes it and no proxy would ever run the guard. Add a stereotype '
                .'such as #[Service], wire it from a #[Bean] factory method, or call the pattern through '
                .'ResilienceRegistry at the call site.'
            );
        }

        $child = $subclasses[0];

        if ($this->carriesClassLevelRule($reflection)) {
            throw new ConfigurationException(
                "Resilience attributes on {$site} cannot be applied: the attribute is written at CLASS level on a "
                .'class nothing post-processes, and a class-level #[Retry]/#[CircuitBreaker]/#[RateLimiter]/'
                .'#[Bulkhead]/#[TimeLimiter] is NOT INHERITED — PHP does not inherit class attributes, so the '
                ."post-processed subclass {$child} carries none of its own and compiles no row, and no proxy would "
                ."ever run the guard. Move the class-level attribute onto {$child} (the stereotyped subclass), write "
                ."it on {$rule->method}() instead — a method attribute IS visible through an inherited method the "
                .'subclass does not override — add a stereotype such as #[Service] here, or call the pattern through '
                .'ResilienceRegistry at the call site.'
            );
        }

        $override = $this->overridingSubclass($rule->method, $subclasses);

        if ($override !== null) {
            throw new ConfigurationException(
                "Resilience attributes on {$site} cannot be applied: nothing post-processes this class, and the "
                ."post-processed subclass {$override} OVERRIDES {$rule->method}() without repeating the attribute — "
                .'PHP does not inherit a method attribute across an override, so the subclass compiles no row and no '
                ."proxy would ever run the guard. Repeat the attribute on {$override}::{$rule->method}(), add a "
                .'stereotype such as #[Service] here, or call the pattern through ResilienceRegistry at the call site.'
            );
        }

        throw new ConfigurationException(
            "Resilience attributes on {$site} cannot be applied: nothing post-processes this class and no "
            ."post-processed subclass of it in the scanned roots compiles a row for {$rule->method}(), so no proxy "
            .'would ever run the guard. Add a stereotype such as #[Service], wire it from a #[Bean] factory method, '
            .'or call the pattern through ResilienceRegistry at the call site.'
        );
    }

    /**
     * An ABSTRACT ancestor carrying a CLASS-LEVEL resilience attribute is inert in every configuration there
     * is, and nothing else in this scan would ever say so. `classes()` walks only instantiable classes,
     * because only those can be beans, so the abstract class is never scanned in its own right; and PHP hands
     * its class attributes down to nobody, so `getAttributes()` on this class — the first concrete descendant
     * the scan reached — returns [] for it. The attribute compiles into nothing at all: no descriptor, no
     * exception, no warning, on a #[Retry] somebody wrote. A CONCRETE annotated ancestor needs none of this:
     * it is scanned in its own right and answers for itself in the second pass.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function refuseAbstractAncestorRule(ReflectionClass $reflection): void
    {
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if (! $parent->isAbstract() || ! $this->carriesClassLevelRule($parent)) {
                continue;
            }

            throw new ConfigurationException(
                "Resilience attributes on {$parent->getName()} cannot be applied: the attribute is written at CLASS "
                .'level on an ABSTRACT class, which can never be a bean, and a class-level #[Retry]/'
                .'#[CircuitBreaker]/#[RateLimiter]/#[Bulkhead]/#[TimeLimiter] is NOT INHERITED — PHP does not inherit '
                ."class attributes, so {$reflection->getName()} carries none of its own and compiles no row. Write "
                .'the attribute on the methods instead — a method attribute IS visible through an inherited method '
                .'the subclass does not override — move it onto the concrete subclass that is the bean '
                ."({$reflection->getName()} is one of them), or call the pattern through ResilienceRegistry at the "
                .'call site.'
            );
        }
    }

    /**
     * Whether the class carries a resilience attribute of its OWN at class level — the one provenance question
     * the refusals above need, and the reason they can say "is NOT INHERITED" rather than guess. #[Fallback]
     * is absent by construction: it has no class-level form.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function carriesClassLevelRule(ReflectionClass $reflection): bool
    {
        return $reflection->getAttributes(Bulkhead::class) !== []
            || $reflection->getAttributes(TimeLimiter::class) !== []
            || $reflection->getAttributes(RateLimiter::class) !== []
            || $reflection->getAttributes(CircuitBreaker::class) !== []
            || $reflection->getAttributes(Retry::class) !== [];
    }

    /**
     * The first of those subclasses that RE-DECLARES the method — an override, which is what made the
     * inherited attribute invisible to it.
     *
     * @param  list<class-string>  $subclasses
     * @return class-string|null
     */
    private function overridingSubclass(string $method, array $subclasses): ?string
    {
        foreach ($subclasses as $subclass) {
            $reflection = new ReflectionClass($subclass);

            if ($reflection->hasMethod($method) && $reflection->getMethod($method)->getDeclaringClass()->getName() === $subclass) {
                return $subclass;
            }
        }

        return null;
    }

    /**
     * Every class a #[Bean] factory method in the scanned roots DECLARES as its return type — the second of
     * the two wiring shapes the bean-post-processor chain is installed for, and the reason the stereotype
     * refusal above is not a stereotype test.
     *
     * The rules are ComponentScanner's, read back rather than re-invented: a #[Bean] method is honoured on
     * any #[Component]-family class (its beansOf() is deliberately unconditional, so a `lite mode` #[Service]
     * counts as much as a #[Configuration]), and only a non-builtin NAMED return type binds anything — a
     * union, an intersection or a missing type leaves `BeanDescriptor::$returns` empty and nothing is bound.
     * An INTERFACE return type is kept out for the opposite reason to the one that would first come to mind:
     * it IS a binding, but the declared class the chain hands the post-processor is then the interface, which
     * no proxy plan is keyed by — so a guard on the concrete class behind it still never runs.
     *
     * @param  list<class-string>  $classes
     * @return array<string, true>
     */
    private function beanFactoryTypes(array $classes): array
    {
        $types = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(Bean::class) === []) {
                    continue;
                }

                $returns = $method->getReturnType();
                if (! $returns instanceof ReflectionNamedType || $returns->isBuiltin()) {
                    continue;
                }

                $name = $returns->getName();
                if (! class_exists($name)) {
                    continue;
                }

                $types[$name] = true;
            }
        }

        return $types;
    }

    /**
     * @template T of object
     *
     * @param  list<ReflectionAttribute<T>>  $attributes
     * @return T|null
     */
    private function first(array $attributes): ?object
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @param  array<string, string>  $psr4
     * @return list<class-string>
     */
    private function classes(array $psr4): array
    {
        $classes = [];
        foreach ($psr4 as $prefix => $dir) {
            $prefix = rtrim($prefix, '\\').'\\';
            if (! is_dir($dir)) {
                continue;
            }
            $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr((string) $file->getRealPath(), strlen($realDir) + 1, -4);
                $class = $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                if (! class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }
                /** @var class-string $class */
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
