<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Merges every AdviceSource's scan into one ProxyPlan, and turns a plan back into the ProxyClassGenerator's
 * inputs. Sources are applied in Advice::order (ties broken by id), so the per-method advice lists — and
 * therefore the interceptor chains the generator emits — are outermost-first by construction. Signatures come
 * from TransactionalScanner::signatures(), the one sanctioned reflection site, so this class stays free of it.
 *
 * @phpstan-import-type PlanRow from ProxyPlan
 */
final class ProxyPlanner
{
    /** @var list<AdviceSource> */
    private readonly array $sources;

    /**
     * @param  list<AdviceSource>  $sources
     */
    public function __construct(array $sources)
    {
        usort($sources, static fn (AdviceSource $a, AdviceSource $b): int => $a->advice()->order <=> $b->advice()->order ?: strcmp($a->advice()->id, $b->advice()->id));
        $this->sources = $sources;
    }

    public static function transactionalOnly(): self
    {
        return new self([new TransactionalAdviceSource]);
    }

    /**
     * @param  array<string, string>  $psr4
     */
    public function plan(array $psr4): ProxyPlan
    {
        /** @var array<class-string, PlanRow> $classes */
        $classes = [];

        foreach ($this->sources as $source) {
            $advice = $source->advice();
            foreach ($source->scan($psr4) as $class => $methods) {
                $classes[$class] ??= ['proxyClass' => $class.ProxyPlan::PROXY_SUFFIX, 'advice' => [], 'methods' => []];
                $classes[$class]['advice'][$advice->id] = $advice->toArray();
                foreach ($methods as $method => $row) {
                    $classes[$class]['methods'][$method][] = ['advice' => $advice->id, 'row' => $row];
                }
            }
        }

        ksort($classes);

        return new ProxyPlan($classes);
    }

    /**
     * @return array<class-string, array<string, ProxyMethod>>
     */
    public function proxyMethods(ProxyPlan $plan): array
    {
        $byId = [];
        foreach ($this->sources as $source) {
            $byId[$source->advice()->id] = $source;
        }

        $scanner = new TransactionalScanner;
        $result = [];

        foreach ($plan->classes() as $class) {
            $methods = $plan->methodsFor($class);
            $signatures = $scanner->signatures($class, array_keys($methods));

            foreach ($methods as $name => $rows) {
                $bound = [];
                foreach ($rows as $row) {
                    $source = $byId[$row['advice']] ?? throw new ConfigurationException("The proxy plan applies advice [{$row['advice']}] to {$class}::{$name}, but no AdviceSource with that id was given to the planner.");
                    $bound[] = new BoundAdvice($source->advice(), $source->render($row['row']));
                }

                $signature = $signatures[$name];
                $result[$class][$name] = new ProxyMethod($name, $signature->paramSource, $signature->argSource, $signature->returnType, $bound);
            }
        }

        return $result;
    }
}
