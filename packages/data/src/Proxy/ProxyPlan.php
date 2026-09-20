<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free answer to "which beans get a proxy, and which advice does each method run":
 * one row per class naming its proxy class, the advice kinds it uses (keyed by id, in chain order) and, per
 * method, the ORDERED list of (advice id, descriptor row) pairs. Loaded via require+map like every other
 * manifest; every value is a scalar or array so it var_exports as a literal.
 *
 * TransactionalBeanPostProcessor reads THIS, not the TransactionalManifest, to decide what to wrap — a class
 * whose only rules are #[PreAuthorize] has no transactional row at all and used to be invisible to the
 * post-processor. fromTransactionalManifest() is the bridge for a boot that has no scan paths but a bound
 * manifest (the data capstone fixtures compile theirs inline): it derives a transactional-only plan so those
 * beans are wrapped exactly as before.
 *
 * @phpstan-type AdviceRow array{id: string, interceptor: string, descriptor: string, order: int}
 * @phpstan-type MethodAdviceRow array{advice: string, row: array<string, mixed>}
 * @phpstan-type PlanRow array{proxyClass: string, advice: array<string, AdviceRow>, methods: array<string, list<MethodAdviceRow>>}
 */
final class ProxyPlan
{
    public const string PROXY_SUFFIX = '__FireflyTransactionalProxy';

    /**
     * @param  array<class-string, PlanRow>  $classes
     */
    public function __construct(private readonly array $classes) {}

    /**
     * @param  array<class-string, PlanRow>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Proxy plan not found at {$path}. Run `php artisan firefly:cache`.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Proxy plan at {$path} did not return an array.");
        }

        /** @var array<class-string, PlanRow> $data */
        return new self($data);
    }

    public static function fromTransactionalManifest(TransactionalManifest $manifest): self
    {
        $advice = Advice::transactional();
        /** @var array<class-string, PlanRow> $classes */
        $classes = [];

        foreach ($manifest->all() as $class => $proxy) {
            $methods = [];
            foreach ($proxy['methods'] as $method => $row) {
                $methods[$method] = [['advice' => $advice->id, 'row' => $row]];
            }
            $classes[$class] = ['proxyClass' => $proxy['proxyClass'], 'advice' => [$advice->id => $advice->toArray()], 'methods' => $methods];
        }

        return new self($classes);
    }

    /**
     * @return array<class-string, PlanRow>
     */
    public function toArray(): array
    {
        return $this->classes;
    }

    /**
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_keys($this->classes);
    }

    public function hasProxyFor(string $class): bool
    {
        return isset($this->classes[$class]);
    }

    public function proxyClassFor(string $class): string
    {
        return ($this->classes[$class] ?? throw new ConfigurationException("No proxy is planned for [{$class}]."))['proxyClass'];
    }

    /**
     * @return array<string, Advice> keyed by advice id, in chain order (outermost first)
     */
    public function adviceFor(string $class): array
    {
        $advice = [];
        foreach ($this->classes[$class]['advice'] ?? [] as $id => $row) {
            $advice[$id] = Advice::fromArray($row);
        }
        uasort($advice, static fn (Advice $a, Advice $b): int => $a->order <=> $b->order ?: strcmp($a->id, $b->id));

        return $advice;
    }

    /**
     * @return array<string, list<MethodAdviceRow>>
     */
    public function methodsFor(string $class): array
    {
        return $this->classes[$class]['methods'] ?? [];
    }
}
