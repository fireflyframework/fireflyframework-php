<?php

declare(strict_types=1);

namespace Firefly\Observability\Scanner;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Method\Counted;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Method\Observed;
use Firefly\Observability\Method\Timed;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The SCAN-TIME half of the observability advice, and the only reflecting file this package adds — the
 * MethodSecurityScanner idiom, deliberately: walk the app's PSR-4 roots, read the effective #[Timed],
 * #[Counted] and #[Observed] on each public method (a method-level attribute REPLACES a class-level one of
 * the same kind, Spring/Micrometer semantics), normalise each to a flat row, and refuse — loudly, here,
 * where a person is running `firefly:cache` and can read the message — anything that would compile and then
 * be honoured by nothing.
 *
 * THE CLASS-LEVEL FAN-OUT REACHES INHERITED METHODS. A class-level attribute applies to every public
 * INSTANCE method the class EXPOSES, which is `getMethods(IS_PUBLIC)` — the ones it declares and the ones it
 * inherits alike. That is the right surface (the generated proxy overrides an inherited method as readily as
 * a declared one, so the meter does fire) but it is a wider surface than "the methods in this file", and the
 * two refusals below are shaped around the difference.
 *
 * WHAT IS REFUSED, AND WHY EACH ONE IS A REFUSAL RATHER THAN A SKIP:
 *
 *   - A class NOTHING POST-PROCESSES is never wrapped, so no proxy runs the meter and no other seam looks
 *     its rows up. A timer that never records is worse than no timer: a dashboard with a flat line says
 *     "this code is not being called". "Nothing post-processes it" is asked of the wiring rather than of the
 *     stereotype alone, because the stereotype is not the whole rule: RegisterBeanPostProcessorsPass installs
 *     the chain for a #[Component]-family class AND for the output of a #[Bean] factory method (keyed on the
 *     method's declared return type), and TransactionalBeanPostProcessor keys on the compiled PLAN, not on a
 *     stereotype — which is why `#[Bean] public function gateway(): StripeGateway` on an unstereotyped
 *     concrete class is proxied today, and why #[Transactional] on that same class works. That is not a
 *     reading of the source but a test: packages/data's CapstoneTransactionalIntegrationTest resolves an
 *     unstereotyped, #[Bean]-wired BeanWiredLedger from a booted context, gets the generated proxy back and
 *     watches its transaction roll back. A stereotype-only refusal would reject that wiring while telling its
 *     author something untrue about it, so this one fires only when the scanned roots show NEITHER shape, and
 *     its message says which two it looked for.
 *   - …and it does not fire for a CONCRETE BASE CLASS whose post-processed child COMPILES THE SAME ROW.
 *     Writing the attribute on a template-method base and stereotyping the leaf is a mainstream shape, and
 *     the metric does record there — but only through the one door PHP leaves open, so the drop is decided
 *     PER METHOD against the rows the post-processed subclasses really compiled, never per class, and
 *     against ALL of them: one child that merely inherits the annotated method does not vouch for a sibling
 *     that overrides it, which would leave that sibling's bean unmetered with nothing said. That door
 *     is the METHOD-level attribute on a method the child does not override: `ReflectionMethod::getAttributes()`
 *     reads the DECLARING class, so the child sees it, compiles its own row for it and the child's proxy
 *     overrides the inherited body. The base's copy is then dropped rather than refused — the base is not a
 *     bean, so a row keyed by it would compile a proxy nothing ever wraps — and that is the one case where
 *     nothing is lost. The two shapes where the child compiles NOTHING are refused like any other
 *     unenforceable rule, because PHP inherits neither:
 *       * a CLASS-level attribute on the base — `ReflectionClass::getAttributes()` does not walk the parents,
 *         so the child returns [] for it and re-derives nothing. Refused at the base; and when that base is
 *         ABSTRACT, refused from the first concrete descendant scanned, because `classes()` never walks an
 *         abstract class in its own right and nothing else in the scan would ever mention it;
 *       * a METHOD-level attribute on a method the child OVERRIDES — an override carries its own, empty,
 *         attribute list.
 *     Dropping either compiles the attribute into nothing at all: no descriptor, no exception, no warning, on
 *     a `#[Timed]` somebody wrote — the exact silent no-op this scan exists to refuse.
 *   - …and a class is only ever refused for the rules it is RESPONSIBLE for. A rule that reached an
 *     unstereotyped class purely by inheritance — the attribute is on an ancestor, the class declares neither
 *     it nor the method — is dropped in silence: a second, hand-written subclass of an annotated base is not
 *     a site whose author can act on "add a stereotype such as #[Service]" (they would grep it for a metric
 *     attribute and find none), and the annotated ancestor is where the refusal, if one is owed, already
 *     fires. Same unactionable-remedy rule as the ancestor-final-method carve-out below.
 *   - A class the advice must proxy while being `final` cannot be extended. Refused, same reason as the first.
 *   - A `final` METHOD cannot be overridden, and the generated proxy overrides every planned method. Without
 *     a refusal the plan compiles and the `require` of the generated class fatals with "Cannot override final
 *     method", naming neither the attribute nor the class-level rule that reached the method. The refusal
 *     fires only where its own remedy is available to the person reading it: the attribute was written on the
 *     method, or the class DECLARES the final method. A `final` method reached purely by the fan-out from an
 *     ANCESTOR is skipped in silence instead — "remove `final` from the method" is not an instruction an
 *     author can follow about a base class they do not own (`AutoConfiguration::register()` is final, and a
 *     #[Service] #[Timed] subclass of any such base would otherwise hard-fail `firefly:cache`), and a method
 *     the timed class never declared is not part of the surface its author asked to time.
 *   - A `static` or `__`-prefixed method carrying an attribute of its OWN. Neither can be intercepted: a
 *     static call has no instance for a proxy to wrap, and the `__firefly*` members the generated proxy
 *     declares make the magic methods its own. Reached by the class-level fan-out these are skipped in
 *     silence (the author wrote one attribute about the class, not one about `__invoke`); written EXPLICITLY
 *     on `__invoke()` — the single-action-service shape — or on a `public static`, they are the exact
 *     "compiles and is then honoured by nothing" this scan exists to refuse, so they are refused.
 *   - #[Timed(percentiles:)] — see the attribute's own docblock. Percentile summaries are a documented
 *     Known-latent of this package; the message names `firefly.observability.metrics.distribution.per-meter`,
 *     which is where a percentile actually comes from here.
 *   - Two attributes on ONE method that spell out the SAME meter name for two different meter TYPES —
 *     #[Timed('orders.place')] beside #[Counted('orders.place')], the mainstream one. A Prometheus name has
 *     exactly one type, so the in-process registry refuses the second registration for the life of the
 *     process and the cache-backed one emits two conflicting `# TYPE` lines: either way one of the two
 *     meters the author wrote never reaches a dashboard. Refused here because this is the only place it
 *     CAN be refused — the interceptor's guards keep the failure from spreading, but they cannot invent the
 *     meter back. #[Timed] beside #[Observed] under one name is left alone: both register timers.
 *
 * Unlike security's scanner this one has no controller carve-out: a controller action carrying #[Timed] IS
 * proxied like any other stereotyped bean, because a metric has no dispatch-seam equivalent to fall back on
 * and MetricsFilter already covers what a controller-level timer would duplicate. It does mean the rule on a
 * `final` controller is refused, which is the correct answer rather than a silent no-op.
 *
 * Runs at cache time (and once per process on an uncached dev boot, through the AdviceSource). Production
 * loads the compiled plan.
 *
 * @phpstan-import-type ObservabilityMethodRow from ObservabilityMethodDescriptor
 */
final class ObservabilityMethodScanner
{
    /**
     * @param  array<string, string>  $psr4  namespace-prefix => absolute directory
     * @return list<ObservabilityMethodDescriptor>
     */
    public function scan(array $psr4): array
    {
        $classes = $this->classes($psr4);
        $factoryProduced = $this->beanFactoryTypes($classes);

        // FIRST PASS — compile every class's rows, and remember which of them the class must ANSWER for.
        // Nothing is dropped or refused for being unenforceable yet: whether a base's row may be dropped is a
        // question about the rows its post-processed subclasses compiled, and those are not known until the
        // whole scan has been compiled.
        /** @var array<class-string, list<ObservabilityMethodDescriptor>> $compiled */
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
     * One class's rows, and the subset of them the class is RESPONSIBLE for — the two answers the second pass
     * needs about it. Every refusal that is a property of the SITE alone (an uninterceptable method carrying
     * an attribute by hand, a `final` method, `#[Timed(percentiles:)]`) fires here; the ones that depend on
     * what else was scanned wait for the caller.
     *
     * "Responsible" is ownership of the attribute, not of the method: a class attribute is always the class's
     * own (PHP does not inherit them), and a method attribute belongs to the class that DECLARES the method.
     * A row that reached a class purely by inheritance is the annotated ancestor's to answer for.
     *
     * @param  class-string  $class
     * @return array{0: list<ObservabilityMethodDescriptor>, 1: array<string, true>}
     */
    private function compile(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $classTimed = $this->first($reflection->getAttributes(Timed::class));
        $classCounted = $this->first($reflection->getAttributes(Counted::class));
        $classObserved = $this->first($reflection->getAttributes(Observed::class));

        $classAnnotated = $classTimed !== null || $classCounted !== null || $classObserved !== null;

        $this->refuseAbstractAncestorMetric($reflection);

        /** @var list<ObservabilityMethodDescriptor> $classRules */
        $classRules = [];
        /** @var array<string, true> $responsible */
        $responsible = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $site = $class.'::'.$method->getName();

            // The method's OWN attributes are read FIRST, before any skip: what a skip may silently drop
            // is a rule the class-level attribute fanned onto this method, never one somebody wrote here.
            $ownTimed = $this->first($method->getAttributes(Timed::class));
            $ownCounted = $this->first($method->getAttributes(Counted::class));
            $ownObserved = $this->first($method->getAttributes(Observed::class));
            $annotated = $ownTimed !== null || $ownCounted !== null || $ownObserved !== null;

            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                if ($annotated) {
                    throw new ConfigurationException($this->uninterceptableMessage($site, $method));
                }

                continue;
            }

            $timed = $ownTimed ?? $classTimed;
            $counted = $ownCounted ?? $classCounted;
            $observed = $ownObserved ?? $classObserved;

            if ($timed === null && $counted === null && $observed === null) {
                continue;
            }

            if ($method->isFinal()) {
                if (! $annotated && $method->getDeclaringClass()->getName() !== $class) {
                    // Fanned onto an ANCESTOR's final method: the remedy the message below offers does
                    // not exist for its reader, and the method is not part of this class's own surface.
                    continue;
                }

                throw new ConfigurationException(
                    "Method metrics on {$site} cannot be recorded: the method is final and a proxy must override "
                    .'it. Remove `final` from the method (a class-level #[Timed]/#[Counted]/#[Observed] applies to '
                    .'every public method, this one included), or record the metric through MetricsRecorder at the '
                    .'call site.'
                );
            }

            if ($timed !== null && $timed->percentiles !== []) {
                throw new ConfigurationException(
                    "#[Timed(percentiles:)] on {$site} cannot be honoured: this package publishes fixed histogram "
                    .'buckets, not client-side quantile summaries. Configure the meter under '
                    .'`firefly.observability.metrics.distribution.per-meter` and compute the quantile in the query '
                    .'(Prometheus histogram_quantile), or drop the parameter.'
                );
            }

            $this->refuseMeterTypeCollision($site, $timed, $counted, $observed);

            $classRules[] = new ObservabilityMethodDescriptor(
                $class,
                $method->getName(),
                $timed === null ? null : ['name' => $timed->value, 'tags' => $timed->extraTags, 'description' => $timed->description, 'longTask' => $timed->longTask],
                $counted === null ? null : ['name' => $counted->value, 'tags' => $counted->extraTags, 'failuresOnly' => $counted->recordFailuresOnly],
                $observed === null ? null : ['name' => $observed->name, 'contextualName' => $observed->contextualName, 'tags' => $observed->lowCardinalityKeyValues],
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
     * The rows the proxy plan enforces — here, all of them: every rule this scanner accepts is one only a
     * proxy can apply, so unlike security's selection there is nothing to filter out.
     *
     * @param  array<string, string>  $psr4
     * @return array<class-string, array<string, ObservabilityMethodRow>>
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

        return "Method metrics on {$site} cannot be recorded: {$why}. Move the attribute to a public instance "
            .'method — a single-action service can keep `__invoke()` as a one-line delegate to a `handle()` that '
            .'carries the meter — or record the metric through MetricsRecorder at the call site.';
    }

    /**
     * Two attributes on ONE method that claim ONE meter name for two different meter TYPES — the last shape
     * this scan refuses, and the only one whose victim is a meter the author never wrote anything wrong
     * about.
     *
     * A Prometheus metric name has exactly one type globally, and neither registry can make that untrue.
     * SimpleMeterRegistry — the default, in-process one — fails fast in guardType(): a name already registered
     * as a timer answers "Metric 'orders.place' already registered as timer; cannot re-register as counter",
     * and it remembers the type for the life of the process, so #[Timed('orders.place')] beside
     * #[Counted('orders.place')] is not a meter recorded twice but a meter recorded once and a refusal on every
     * invocation afterwards. CacheMeterRegistry keys its index by type AND name, so it raises nothing and
     * stores both — and PrometheusTextFormat groups families the same way, which puts two `# TYPE` lines for
     * one name in the exposition and makes the whole scrape invalid. Neither outcome is the one the author
     * meant. The interceptor guards each record*() separately so a refusal costs only its own sample, but a
     * counter that can never register is exactly the flat line the rest of this scan exists to prevent — and
     * here, unlike a store outage, the person reading the message can act on it.
     *
     * Only a name the ATTRIBUTE spells out is compared. An empty `value` falls back to
     * `firefly.observability.method.<kind>.name` at RUNTIME, which this scanner has no Config to read, and the
     * three defaults (`method.timed`, `method.counted`, `method.observed`) do not collide — so a refusal there
     * would either be a guess or a duplicate of a default this file does not own. The pairs that can collide
     * are the ones whose types differ: #[Timed] and #[Observed] both register TIMERS and may legitimately share
     * a name, while a #[Counted] beside either, or a `longTask` gauge (`<meter>.active`) beside any of them, is
     * a type conflict.
     */
    private function refuseMeterTypeCollision(string $site, ?Timed $timed, ?Counted $counted, ?Observed $observed): void
    {
        /** @var array<string, array{0: string, 1: string, 2: string}> $claimed */
        $claimed = [];

        foreach ($this->meterClaims($timed, $counted, $observed) as $claim) {
            [$name, $type, $attribute] = $claim;
            $existing = $claimed[$name] ?? null;

            if ($existing !== null && $existing[1] !== $type) {
                throw new ConfigurationException(
                    "{$existing[2]} and {$attribute} on {$site} both name the meter '{$name}', but a metric name "
                    ."has exactly ONE type: '{$name}' cannot be a {$existing[1]} and a {$type} at once. The "
                    .'in-process registry refuses the second registration outright, so that meter never records '
                    .'at all; the cache-backed one writes both and the exposition carries two conflicting '
                    .'`# TYPE` lines for one name. Give the two meters different names — a timer already '
                    .'publishes its own `_count`, so a counter beside one is usually redundant — or drop one '
                    .'of the attributes.'
                );
            }

            $claimed[$name] = $claim;
        }
    }

    /**
     * Every meter the three attributes name OUTRIGHT, as [name, meter type, the attribute that claims it] —
     * the scan-time half of what the interceptor will ask the registry for. `longTask` is included because it
     * publishes a second meter of a third type under a name derived from the timer's, and a collision on
     * `<meter>.active` is the same fail-fast as a collision on `<meter>`.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function meterClaims(?Timed $timed, ?Counted $counted, ?Observed $observed): array
    {
        $claims = [];

        if ($timed !== null && $timed->value !== '') {
            $claims[] = [$timed->value, 'timer', '#[Timed]'];

            if ($timed->longTask) {
                $claims[] = [$timed->value.'.active', 'gauge', '#[Timed(longTask:)]'];
            }
        }

        if ($counted !== null && $counted->value !== '') {
            $claims[] = [$counted->value, 'counter', '#[Counted]'];
        }

        if ($observed !== null && $observed->name !== '') {
            $claims[] = [$observed->name, 'timer', '#[Observed]'];
        }

        return $claims;
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
     * The method names EVERY ONE of those subclasses REALLY COMPILED A ROW FOR — the per-method premise the
     * drop rests on, read off the first pass rather than re-derived from the shape of the hierarchy.
     *
     * Asking it per CLASS ("a post-processed subclass exists, therefore the child already has its own row,
     * therefore nothing is lost") is true for exactly one of the two ways an attribute reaches a method.
     * `ReflectionMethod::getAttributes()` reads the DECLARING class, so a METHOD-level attribute on a method
     * the child does not override IS visible through the child. `ReflectionClass::getAttributes()` walks no
     * parents, so a CLASS-level attribute on the base is invisible to the child; and an override carries its
     * own, empty, attribute list. In those two shapes the child compiles nothing, and a per-class drop threw
     * the author's `#[Timed]` away in silence.
     *
     * And it is an INTERSECTION over the subclasses rather than a union, for the same reason it is per method
     * rather than per class: a flat set of names unioned over them let ONE child that merely inherits the
     * annotated method vouch for a SIBLING that overrides it without repeating the attribute. The base's row
     * was dropped as losing nothing, the overriding bean was left unmetered — no row, no exception, no
     * warning, on a `#[Timed]` somebody wrote — and refuseUnenforceable()'s "OVERRIDES … without repeating
     * the attribute" was never reached, although it is exactly the sentence that case needs. A method is
     * covered only when EVERY post-processed subclass compiled a row for it; the first one that did not sends
     * the base's row to the refusal, which names that subclass.
     *
     * @param  list<class-string>  $subclasses
     * @param  array<class-string, list<ObservabilityMethodDescriptor>>  $compiled
     * @return array<string, true>
     */
    private function coveredMethods(array $subclasses, array $compiled): array
    {
        /** @var array<string, true>|null $covered */
        $covered = null;

        foreach ($subclasses as $subclass) {
            /** @var array<string, true> $own */
            $own = [];
            foreach ($compiled[$subclass] ?? [] as $rule) {
                $own[$rule->method] = true;
            }

            $covered = $covered === null ? $own : array_intersect_key($covered, $own);
        }

        return $covered ?? [];
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
     * and which row the message names would be arbitrary.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function refuseUnproxyableClass(ReflectionClass $reflection): void
    {
        if (! $reflection->isFinal()) {
            return;
        }

        throw new ConfigurationException(
            "Method metrics on {$reflection->getName()} cannot be recorded: the class is final and a proxy must "
            .'extend it. Remove `final`, or record the metric through MetricsRecorder at the call site.'
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
    private function refuseUnenforceable(ReflectionClass $reflection, ObservabilityMethodDescriptor $rule, array $subclasses): void
    {
        $site = $rule->key();

        if ($subclasses === []) {
            throw new ConfigurationException(
                "Method metrics on {$site} cannot be recorded: the class carries no #[Component]-family "
                .'stereotype, no #[Bean] method in the scanned roots returns it and no post-processed subclass of it '
                .'was scanned, so nothing post-processes it and no proxy would ever run the meter. Add a stereotype '
                .'such as #[Service], wire it from a #[Bean] factory method, or record the metric through '
                .'MetricsRecorder at the call site.'
            );
        }

        $child = $subclasses[0];

        if ($this->carriesClassLevelMetric($reflection)) {
            throw new ConfigurationException(
                "Method metrics on {$site} cannot be recorded: the attribute is written at CLASS level on a class "
                .'nothing post-processes, and a class-level #[Timed]/#[Counted]/#[Observed] is NOT INHERITED — PHP '
                ."does not inherit class attributes, so the post-processed subclass {$child} carries none of its own "
                .'and compiles no row, and no proxy would ever run the meter. Move the class-level attribute onto '
                ."{$child} (the stereotyped subclass), write it on {$rule->method}() instead — a method attribute IS "
                .'visible through an inherited method the subclass does not override — add a stereotype such as '
                .'#[Service] here, or record the metric through MetricsRecorder at the call site.'
            );
        }

        $override = $this->overridingSubclass($rule->method, $subclasses);

        if ($override !== null) {
            throw new ConfigurationException(
                "Method metrics on {$site} cannot be recorded: nothing post-processes this class, and the "
                ."post-processed subclass {$override} OVERRIDES {$rule->method}() without repeating the attribute — "
                .'PHP does not inherit a method attribute across an override, so the subclass compiles no row and no '
                ."proxy would ever run the meter. Repeat the attribute on {$override}::{$rule->method}(), add a "
                .'stereotype such as #[Service] here, or record the metric through MetricsRecorder at the call site.'
            );
        }

        throw new ConfigurationException(
            "Method metrics on {$site} cannot be recorded: nothing post-processes this class and no post-processed "
            ."subclass of it in the scanned roots compiles a row for {$rule->method}(), so no proxy would ever run "
            .'the meter. Add a stereotype such as #[Service], wire it from a #[Bean] factory method, or record the '
            .'metric through MetricsRecorder at the call site.'
        );
    }

    /**
     * An ABSTRACT ancestor carrying a CLASS-LEVEL metric attribute is inert in every configuration there is,
     * and nothing else in this scan would ever say so. `classes()` walks only instantiable classes, because
     * only those can be beans, so the abstract class is never scanned in its own right; and PHP hands its
     * class attributes down to nobody, so `getAttributes()` on this class — the first concrete descendant the
     * scan reached — returns [] for it. The attribute compiles into nothing at all: no descriptor, no
     * exception, no warning, on a `#[Timed]` somebody wrote, which is the silent no-op this scan exists to
     * refuse. A CONCRETE annotated ancestor needs none of this: it is scanned in its own right and answers for
     * itself in the second pass, where the whole scan is in view.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function refuseAbstractAncestorMetric(ReflectionClass $reflection): void
    {
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if (! $parent->isAbstract() || ! $this->carriesClassLevelMetric($parent)) {
                continue;
            }

            throw new ConfigurationException(
                "Method metrics on {$parent->getName()} cannot be recorded: the attribute is written at CLASS level "
                .'on an ABSTRACT class, which can never be a bean, and a class-level #[Timed]/#[Counted]/#[Observed] '
                ."is NOT INHERITED — PHP does not inherit class attributes, so {$reflection->getName()} carries none "
                .'of its own and compiles no row. Write the attribute on the methods instead — a method attribute IS '
                .'visible through an inherited method the subclass does not override — move it onto the concrete '
                ."subclass that is the bean ({$reflection->getName()} is one of them), or record the metric through "
                .'MetricsRecorder at the call site.'
            );
        }
    }

    /**
     * Whether the class carries a metric attribute of its OWN at class level — the one provenance question the
     * refusals above need, and the reason they can say "is NOT INHERITED" rather than guess.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function carriesClassLevelMetric(ReflectionClass $reflection): bool
    {
        return $reflection->getAttributes(Timed::class) !== []
            || $reflection->getAttributes(Counted::class) !== []
            || $reflection->getAttributes(Observed::class) !== [];
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
     * no proxy plan is keyed by — so a metric on the concrete class behind it still records nothing.
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
