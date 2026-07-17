<?php

declare(strict_types=1);

namespace Firefly\Web\Filter;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;

/**
 * Collects WebFilter beans, sorts them by #[Order] READ FROM THE MANIFEST ($def->descriptor->order — never
 * a resolved instance's class; M4 invariant #3), prepends the two framework filters (fixed orders), and
 * pushes the ordered list onto Laravel's global middleware stack — so every WebFilter runs inside the same
 * HTTP-kernel pipeline as CORS/CSRF/secure-headers middleware.
 */
final class FilterChainRegistrar implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 100;
    }

    public function run(BootContext $context): void
    {
        $kernel = $this->resolveHttpKernel($context);
        if (! $kernel instanceof FoundationHttpKernel) {
            return;
        }

        foreach ($this->orderedFilters($context) as $filterClass) {
            $kernel->pushMiddleware($filterClass);
        }
    }

    /**
     * Resolved through a dedicated method with an EXPLICIT `object` return type, rather than inline at the
     * call site: Larastan's container-return-type extension narrows `container->make(HttpKernelContract::class)`
     * to whatever concrete Kernel the analysis-time (testbench) app happens to bind, which would make the
     * instanceof guard below look like dead code to PHPStan even though a real host application can bind ANY
     * Kernel-contract implementation. Declaring `object` here is the honest static type — the contract only
     * promises an object implementing four methods, not a FoundationHttpKernel — so the instanceof below is a
     * real narrowing, not a suppressed one.
     */
    private function resolveHttpKernel(BootContext $context): object
    {
        return $context->container->make(HttpKernelContract::class);
    }

    /**
     * @return list<class-string>
     */
    public function orderedFilters(BootContext $context): array
    {
        $beans = [];
        foreach ($context->definitions->all() as $definition) {
            if (is_a($definition->class(), WebFilter::class, true)) {
                $beans[] = ['class' => $definition->class(), 'order' => $definition->descriptor->order];
            }
        }

        usort($beans, static function (array $a, array $b): int {
            return $a['order'] <=> $b['order'] ?: strcmp($a['class'], $b['class']);
        });

        return array_merge(
            [RequestContextFilter::class, CorrelationIdFilter::class],
            array_map(static fn (array $bean): string => $bean['class'], $beans),
        );
    }
}
