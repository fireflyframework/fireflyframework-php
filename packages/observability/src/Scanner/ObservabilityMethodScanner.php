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
 *   - …and it does not fire for a CONCRETE BASE CLASS whose post-processed child is in the same scan. Writing
 *     the attribute on a template-method base and stereotyping the leaf is a mainstream shape, and the metric
 *     does record there: the child exposes the inherited method, so the child already has its own row (see
 *     the fan-out note above) and the child's proxy overrides the inherited body. The base's own rows are
 *     dropped rather than refused — it is not a bean, so a row keyed by it would compile a proxy nothing ever
 *     wraps — and nothing is lost in dropping them, which is why this one is silent where the others are not.
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
        $rules = [];
        $classes = $this->classes($psr4);
        $factoryProduced = $this->beanFactoryTypes($classes);

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            $classTimed = $this->first($reflection->getAttributes(Timed::class));
            $classCounted = $this->first($reflection->getAttributes(Counted::class));
            $classObserved = $this->first($reflection->getAttributes(Observed::class));

            /** @var list<ObservabilityMethodDescriptor> $classRules */
            $classRules = [];

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

                $classRules[] = new ObservabilityMethodDescriptor(
                    $class,
                    $method->getName(),
                    $timed === null ? null : ['name' => $timed->value, 'tags' => $timed->extraTags, 'description' => $timed->description, 'longTask' => $timed->longTask],
                    $counted === null ? null : ['name' => $counted->value, 'tags' => $counted->extraTags, 'failuresOnly' => $counted->recordFailuresOnly],
                    $observed === null ? null : ['name' => $observed->name, 'contextualName' => $observed->contextualName, 'tags' => $observed->lowCardinalityKeyValues],
                );
            }

            if ($classRules === []) {
                continue;
            }

            if ($this->enforcedThroughSubclass($reflection, $classes, $factoryProduced)) {
                continue;
            }

            $this->refuseUnenforceable($reflection, $classRules, $factoryProduced);
            $rules = [...$rules, ...$classRules];
        }

        return $rules;
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
     * Whether the class's rules are already enforced by a POST-PROCESSED SUBCLASS in the same scan — the
     * template-method shape: a concrete base carries the attribute and a #[Service] leaf is the bean. The
     * child exposes the inherited method, so `scan()` has already compiled the child's own row for it and the
     * child's proxy overrides the inherited body; the base's rows are the ones with nowhere to go, and are
     * dropped by the caller rather than refused. Only the class's OWN wiring is asked about first: a base that
     * is itself a bean keeps its rows, subclass or no subclass.
     *
     * @param  ReflectionClass<object>  $reflection
     * @param  list<class-string>  $classes
     * @param  array<string, true>  $factoryProduced
     */
    private function enforcedThroughSubclass(ReflectionClass $reflection, array $classes, array $factoryProduced): bool
    {
        if ($this->postProcessed($reflection, $factoryProduced)) {
            return false;
        }

        $class = $reflection->getName();

        foreach ($classes as $candidate) {
            if ($candidate === $class || ! is_subclass_of($candidate, $class)) {
                continue;
            }

            if ($this->postProcessed(new ReflectionClass($candidate), $factoryProduced)) {
                return true;
            }
        }

        return false;
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
     * @param  ReflectionClass<object>  $reflection
     * @param  list<ObservabilityMethodDescriptor>  $rules
     * @param  array<string, true>  $factoryProduced  classes a #[Bean] method in the scanned roots returns
     */
    private function refuseUnenforceable(ReflectionClass $reflection, array $rules, array $factoryProduced): void
    {
        $class = $reflection->getName();

        if (! $this->postProcessed($reflection, $factoryProduced)) {
            throw new ConfigurationException(
                "Method metrics on {$rules[0]->key()} cannot be recorded: the class carries no #[Component]-family "
                .'stereotype, no #[Bean] method in the scanned roots returns it and no post-processed subclass of it '
                .'was scanned, so nothing post-processes it and no proxy would ever run the meter. Add a stereotype '
                .'such as #[Service], wire it from a #[Bean] factory method, or record the metric through '
                .'MetricsRecorder at the call site.'
            );
        }

        if ($reflection->isFinal()) {
            throw new ConfigurationException(
                "Method metrics on {$class} cannot be recorded: the class is final and a proxy must extend it. "
                .'Remove `final`, or record the metric through MetricsRecorder at the call site.'
            );
        }
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
