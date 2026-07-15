<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;

/**
 * Eagerly resolves every non-#[Lazy] Scope::Singleton component and #[Bean] factory, sorted from the
 * MANIFEST by (order, abstract) — never from resolved instances (the same INVARIANT 3 rule the other
 * instance-stage passes document).
 *
 * Runs AFTER EventListeners (800) deliberately: an event published from a #[PostConstruct] callback
 * fired DURING eager resolution must already find its listeners registered, or it reaches nobody,
 * silently (see the design decisions doc's DELTA 2, and EagerSingletonsPassTest's proof). Runs AFTER
 * BeanPostProcessors (700) for the same reason InfrastructureStartPass does: every eagerly resolved
 * bean must already have its composite extender installed.
 *
 * #[Lazy] on a #[Component] CLASS is read straight off ComponentDescriptor::$lazy, and #[Lazy] on a
 * #[Bean] factory METHOD is read straight off BeanDescriptor::$lazy — no reflection, no boot-time
 * attribute lookup at all in either case (see ComponentScanner, which captures both onto the
 * manifest at scan time).
 */
final class EagerSingletonsPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::EagerSingletons;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        foreach ($this->orderedEagerAbstracts($context) as $abstract) {
            $context->container->make($abstract);
        }
    }

    /**
     * @return list<string>
     */
    private function orderedEagerAbstracts(BootContext $context): array
    {
        /** @var list<array{0: int, 1: string}> $entries */
        $entries = [];

        foreach ($context->definitions->all() as $definition) {
            $descriptor = $definition->descriptor;

            if ($descriptor->scope === Scope::Singleton && ! $descriptor->lazy) {
                $entries[] = [$descriptor->order, $descriptor->class];
            }

            foreach ($descriptor->beans as $bean) {
                $isEager = $bean->returns !== ''
                    && $bean->scope === Scope::Singleton
                    && ! $bean->lazy;

                if ($isEager) {
                    $entries[] = [$bean->order, $bean->returns];
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $entry): string => $entry[1], $entries);
    }
}
