<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Scanner;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Component;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Gating\FeatureFlag;
use Firefly\FeatureFlags\Gating\FeatureFlagMethodDescriptor;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Web\Attributes\RestController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;

/**
 * The ONE file of firefly/feature-flags that reflects (ReflectionFreeFeatureFlagsTest pins it): it compiles
 * #[FeatureFlag] into proxy-plan rows at `firefly:cache` time (and once per process on an uncached dev boot),
 * and refuses, with a sentence, every placement no proxy could enforce — the gate a reader believes is there
 * must be there:
 *
 *  - a final class or a final method (a proxy must extend / override it; a class-level attribute reaches every
 *    public method, so a final one inherited from a vendor base is skipped rather than refused);
 *  - a static, constructor or `__` method carrying the attribute (never routed through the advice chain);
 *  - a class nothing post-processes (no #[Component]-family stereotype, no #[Bean] returns it, no
 *    post-processed subclass compiles a row for the method);
 *  - a fallback that does not exist, is not public, is static, is the gated method itself, or needs more
 *    arguments than the gated call supplies;
 *  - a key that is not a valid flag key, or a variant containing a comma (route middleware parameters are
 *    comma-separated).
 *
 * A method a fallback names is shielded from the CLASS-level attribute (Resilience4j's rule for fallbackMethod):
 * the degraded answer must not be gated by the very flag it stands in for. Rows on a #[RestController] carry
 * `route: true`.
 */
final class FeatureFlagScanner
{
    /**
     * @param  array<string, string>  $psr4  namespace-prefix => absolute directory
     * @return array<class-string, array<string, array<string, mixed>>> class => method => row
     */
    public function scanProxyAdvice(array $psr4): array
    {
        $advice = [];
        foreach ($this->scan($psr4) as $rule) {
            $advice[$rule->class][$rule->method] = $rule->toArray();
        }

        foreach ($advice as $class => $methods) {
            ksort($methods);
            $advice[$class] = $methods;
        }
        ksort($advice);

        /** @var array<class-string, array<string, array<string, mixed>>> $advice */
        return $advice;
    }

    /**
     * @param  array<string, string>  $psr4
     * @return list<FeatureFlagMethodDescriptor>
     */
    public function scan(array $psr4): array
    {
        $classes = $this->classes($psr4);
        $produced = $this->beanFactoryTypes($classes);

        $compiled = [];
        foreach ($classes as $class) {
            $rules = $this->compile($class);
            if ($rules !== []) {
                $compiled[$class] = $rules;
            }
        }

        $rules = [];
        foreach ($compiled as $class => $classRules) {
            $reflection = new ReflectionClass($class);
            $classFlag = $reflection->getAttributes(FeatureFlag::class) !== [];

            if ($this->postProcessed($reflection, $produced)) {
                if ($reflection->isFinal()) {
                    throw new ConfigurationException("#[FeatureFlag] on {$class} cannot be applied: the class is final and a proxy must extend it. Remove `final`, or gate the call with FeatureFlags::isEnabled() where it is made.");
                }
                $rules = [...$rules, ...$classRules];

                continue;
            }

            $covered = $this->coveredBySubclasses($class, $classes, $produced, $compiled);
            foreach ($classRules as $rule) {
                if (! $classFlag && $reflection->getMethod($rule->method)->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (! isset($covered[$rule->method])) {
                    throw new ConfigurationException("#[FeatureFlag] on {$rule->site()} cannot be applied: nothing post-processes {$class} — it has no #[Component]-family stereotype, no #[Bean] method returns it and no post-processed subclass compiles a row for {$rule->method}() — so no proxy would ever run the gate. Add a stereotype such as #[Service], or gate the call with FeatureFlags::isEnabled().");
                }
            }
        }

        return $rules;
    }

    /**
     * @param  class-string  $class
     * @return list<FeatureFlagMethodDescriptor>
     */
    private function compile(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $this->refuseAbstractAncestorRule($reflection);
        $classFlag = $this->first($reflection->getAttributes(FeatureFlag::class));
        $route = $reflection->getAttributes(RestController::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
        $fallbacks = $this->fallbackTargets($reflection, $classFlag);

        $rules = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $own = $this->first($method->getAttributes(FeatureFlag::class));
            $site = $class.'::'.$method->getName();

            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                if ($own !== null) {
                    throw new ConfigurationException("#[FeatureFlag] on {$site} cannot be applied: ".($method->isStatic() ? 'a static call has no instance for a proxy to wrap' : 'a constructor or `__` method is never routed through the advice chain').'. Move the attribute to a public instance method, or gate the call with FeatureFlags::isEnabled().');
                }

                continue;
            }

            $flag = $own ?? (isset($fallbacks[strtolower($method->getName())]) ? null : $classFlag);
            if ($flag === null) {
                continue;
            }

            if ($method->isFinal()) {
                if ($own === null && $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                throw new ConfigurationException("#[FeatureFlag] on {$site} cannot be applied: the method is final and a proxy must override it. Remove `final` (a class-level #[FeatureFlag] applies to every public method, this one included).");
            }

            $this->assertFlag($flag, $site);
            if ($flag->fallback !== null) {
                $this->assertFallback($reflection, $method, $flag->fallback, $site);
            }

            $rules[] = new FeatureFlagMethodDescriptor($class, $method->getName(), $flag->key, $flag->variant, $flag->default, $flag->fallback, $route);
        }

        return $rules;
    }

    private function assertFlag(FeatureFlag $flag, string $site): void
    {
        if (preg_match(FlagDefinitions::KEY_PATTERN, $flag->key) !== 1) {
            throw new ConfigurationException("#[FeatureFlag] on {$site} names [{$flag->key}], which is not a valid flag key (^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\$).");
        }

        if ($flag->variant !== null && ($flag->variant === '' || str_contains($flag->variant, ','))) {
            throw new ConfigurationException("#[FeatureFlag] on {$site} gates on variant [{$flag->variant}]: a variant used by a gate must be non-empty and contain no comma.");
        }
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function assertFallback(ReflectionClass $reflection, ReflectionMethod $gated, string $fallback, string $site): void
    {
        if (strcasecmp($fallback, $gated->getName()) === 0) {
            throw new ConfigurationException("#[FeatureFlag] on {$site} names the gated method itself as its fallback, which would answer by calling the gate again. Name a DIFFERENT method that returns the degraded answer.");
        }

        if (! $reflection->hasMethod($fallback)) {
            throw new ConfigurationException("#[FeatureFlag] on {$site} names fallback [{$fallback}], which {$reflection->getName()} does not declare.");
        }

        $recovery = $reflection->getMethod($fallback);
        if (! $recovery->isPublic() || $recovery->isStatic()) {
            throw new ConfigurationException("#[FeatureFlag] on {$site} names fallback [{$fallback}], which is ".($recovery->isStatic() ? 'static' : 'not public').'. The proxy calls the fallback on the bean from outside the class: make it a public instance method.');
        }

        if ($recovery->getNumberOfRequiredParameters() > $gated->getNumberOfParameters()) {
            throw new ConfigurationException("#[FeatureFlag] on {$site} names fallback [{$fallback}], which requires {$recovery->getNumberOfRequiredParameters()} arguments; the gated call supplies {$gated->getNumberOfParameters()}. Give the fallback the gated method's signature.");
        }
    }

    /**
     * Methods some #[FeatureFlag] on this class names as its fallback: the class-level attribute skips them.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return array<string, true>
     */
    private function fallbackTargets(ReflectionClass $reflection, ?FeatureFlag $classFlag): array
    {
        $targets = [];
        if ($classFlag?->fallback !== null) {
            $targets[strtolower($classFlag->fallback)] = true;
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $fallback = $this->first($method->getAttributes(FeatureFlag::class))?->fallback;
            if ($fallback !== null && strcasecmp($fallback, $method->getName()) !== 0) {
                $targets[strtolower($fallback)] = true;
            }
        }

        return $targets;
    }

    /**
     * The methods every post-processed subclass of $class compiles a row for (an inherited method attribute IS
     * visible on the subclass; a class attribute is not inherited by PHP).
     *
     * @param  list<class-string>  $classes
     * @param  array<string, true>  $produced
     * @param  array<class-string, list<FeatureFlagMethodDescriptor>>  $compiled
     * @return array<string, true>
     */
    private function coveredBySubclasses(string $class, array $classes, array $produced, array $compiled): array
    {
        $covered = null;
        foreach ($classes as $candidate) {
            if ($candidate === $class || ! is_subclass_of($candidate, $class) || ! $this->postProcessed(new ReflectionClass($candidate), $produced)) {
                continue;
            }

            $own = [];
            foreach ($compiled[$candidate] ?? [] as $rule) {
                $own[$rule->method] = true;
            }
            $covered = $covered === null ? $own : array_intersect_key($covered, $own);
        }

        return $covered ?? [];
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     * @param  array<string, true>  $produced
     */
    private function postProcessed(ReflectionClass $reflection, array $produced): bool
    {
        return $reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            || isset($produced[$reflection->getName()]);
    }

    /**
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
                $returns = $method->getReturnType();
                if ($method->getAttributes(Bean::class) !== [] && $returns instanceof ReflectionNamedType && ! $returns->isBuiltin() && class_exists($returns->getName())) {
                    $types[$returns->getName()] = true;
                }
            }
        }

        return $types;
    }

    /**
     * @param  list<ReflectionAttribute<FeatureFlag>>  $attributes
     */
    private function first(array $attributes): ?FeatureFlag
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /** @param ReflectionClass<object> $reflection */
    private function refuseAbstractAncestorRule(ReflectionClass $reflection): void
    {
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if ($parent->isAbstract() && $parent->getAttributes(FeatureFlag::class) !== []) {
                throw new ConfigurationException("#[FeatureFlag] on {$parent->getName()} cannot be applied: a class-level #[FeatureFlag] is not inherited by {$reflection->getName()}, so no proxy could enforce it. Put the attribute on the concrete service or its methods.");
            }
        }
    }

    /**
     * Concrete classes under the PSR-4 roots, sorted (the ResilienceMethodScanner walk).
     *
     * @param  array<string, string>  $psr4
     * @return list<class-string>
     */
    private function classes(array $psr4): array
    {
        $classes = [];
        foreach ($psr4 as $prefix => $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            $prefix = rtrim($prefix, '\\').'\\';
            $root = rtrim((string) realpath($directory), DIRECTORY_SEPARATOR);
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr((string) $file->getRealPath(), strlen($root) + 1, -4);
                $class = $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                if (! class_exists($class)) {
                    continue;
                }
                $reflection = new ReflectionClass($class);
                if (! $reflection->isAbstract() && ! $reflection->isInterface()) {
                    $classes[] = $class;
                }
            }
        }
        sort($classes);

        return $classes;
    }
}
