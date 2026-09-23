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
 * be honoured by nothing:
 *
 *   - A class NOTHING POST-PROCESSES is never wrapped, so no proxy runs the meter and no other seam looks
 *     its rows up. A timer that never records is worse than no timer: a dashboard with a flat line says
 *     "this code is not being called". "Nothing post-processes it" is asked of the wiring rather than of the
 *     stereotype alone, because the stereotype is not the whole rule: RegisterBeanPostProcessorsPass installs
 *     the chain for a #[Component]-family class AND for the output of a #[Bean] factory method (keyed on the
 *     method's declared return type), and TransactionalBeanPostProcessor keys on the compiled PLAN, not on a
 *     stereotype — which is why `#[Bean] public function gateway(): StripeGateway` on an unstereotyped
 *     concrete class is proxied today, and why #[Transactional] on that same class works. A stereotype-only
 *     refusal would reject that wiring while telling its author something untrue about it, so this one fires
 *     only when the scanned roots show NEITHER shape, and its message says which two it looked for.
 *   - A class the advice must proxy while being `final` cannot be extended. Same refusal, same reason.
 *   - A `final` METHOD cannot be overridden, and the generated proxy overrides every planned method. Without
 *     this refusal the plan compiles and the `require` of the generated class fatals with "Cannot override
 *     final method", naming neither the attribute nor the class-level rule that reached the method. The
 *     refusal covers the CLASS-LEVEL fan-out as well as an explicit method-level attribute: silently dropping
 *     the one final method of an otherwise timed class is the flat line this scan exists to prevent, and the
 *     message names the method so `final` can come off it (or the attribute can move).
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
                if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                    continue;
                }

                $timed = $this->first($method->getAttributes(Timed::class)) ?? $classTimed;
                $counted = $this->first($method->getAttributes(Counted::class)) ?? $classCounted;
                $observed = $this->first($method->getAttributes(Observed::class)) ?? $classObserved;

                if ($timed === null && $counted === null && $observed === null) {
                    continue;
                }

                $site = $class.'::'.$method->getName();

                if ($method->isFinal()) {
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
     * @param  ReflectionClass<object>  $reflection
     * @param  list<ObservabilityMethodDescriptor>  $rules
     * @param  array<string, true>  $factoryProduced  classes a #[Bean] method in the scanned roots returns
     */
    private function refuseUnenforceable(ReflectionClass $reflection, array $rules, array $factoryProduced): void
    {
        $class = $reflection->getName();

        if ($reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF) === [] && ! isset($factoryProduced[$class])) {
            throw new ConfigurationException(
                "Method metrics on {$rules[0]->key()} cannot be recorded: the class carries no #[Component]-family "
                .'stereotype and no #[Bean] method in the scanned roots returns it, so nothing post-processes it and '
                .'no proxy would ever run the meter. Add a stereotype such as #[Service], wire it from a #[Bean] '
                .'factory method, or record the metric through MetricsRecorder at the call site.'
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
