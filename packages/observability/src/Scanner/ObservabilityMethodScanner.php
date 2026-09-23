<?php

declare(strict_types=1);

namespace Firefly\Observability\Scanner;

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

/**
 * The SCAN-TIME half of the observability advice, and the only reflecting file this package adds — the
 * MethodSecurityScanner idiom, deliberately: walk the app's PSR-4 roots, read the effective #[Timed],
 * #[Counted] and #[Observed] on each public method (a method-level attribute REPLACES a class-level one of
 * the same kind, Spring/Micrometer semantics), normalise each to a flat row, and refuse — loudly, here,
 * where a person is running `firefly:cache` and can read the message — anything that would compile and then
 * be honoured by nothing:
 *
 *   - A class with no #[Component]-family stereotype is never post-processed, so no proxy wraps it and no
 *     other seam looks its rules up. A timer that never records is worse than no timer: a dashboard with a
 *     flat line says "this code is not being called".
 *   - A class the advice must proxy while being `final` cannot be extended. Same refusal, same reason.
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

        foreach ($this->classes($psr4) as $class) {
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

            $this->refuseUnenforceable($reflection, $classRules);
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
     */
    private function refuseUnenforceable(ReflectionClass $reflection, array $rules): void
    {
        $class = $reflection->getName();

        if ($reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
            throw new ConfigurationException(
                "Method metrics on {$rules[0]->key()} cannot be recorded: the class carries no #[Component]-family "
                .'stereotype, so no proxy wraps it and nothing would ever run the meter. Add a stereotype such as '
                .'#[Service], or record the metric through MetricsRecorder at the call site.'
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
