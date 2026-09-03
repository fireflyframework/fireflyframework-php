<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;

/**
 * Eagerly resolves every non-#[Lazy] Scope::Singleton component and #[Bean] factory, sorted from the
 * MANIFEST by (order, container key) — never from resolved instances (the same INVARIANT 3 rule the
 * other instance-stage passes document).
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
 *
 * EACH EAGER #[Bean] IS RESOLVED BY ITS OWN CONTAINER KEY (BeanBindingKeys), never by
 * `$bean->returns`. That distinction only became visible when firefly/container taught
 * ContainerRegistrar to honour #[Primary]/#[Qualifier] on #[Bean] methods, but it exposed a bug
 * this pass had carried since it was written, and it broke a second way at the same time:
 *
 *  - THE OLD, SILENT BUG. With SEVERAL #[Bean] methods producing one type, this pass queued that
 *    TYPE once per competitor and make()d it each time. The type key is a single binding, so every
 *    call after the first returned the SAME cached singleton: exactly ONE of the competitors was
 *    ever constructed, and every named sibling — a non-#[Lazy] Scope::Singleton bean, which this
 *    pass exists to guarantee is built at boot — was quietly never built at all. No error, no
 *    warning; the "eager singleton" guarantee simply did not hold for it.
 *  - THE NEW, LOUD ONE. A contested type with no #[Primary] is now bound to a factory that throws
 *    a NoUniqueBeanDefinition-style ConfigurationException, so make()ing the bare type turned a
 *    perfectly valid application — two same-typed beans, injected only by #[Qualifier] — into a
 *    hard failure AT BOOT.
 *
 * Resolving by the registrar's own key fixes both at once, and does it without special-casing the
 * common shape: for an UNCONTESTED type the key IS `$bean->returns` (the registrar binds the
 * factory there and aliases the name to it), so single-#[Bean] applications resolve byte-identically
 * to before. See CompetingBeansPassTest.
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
        $keys = BeanBindingKeys::fromDefinitions($context->definitions);

        /** @var list<array{0: int, 1: string}> $entries */
        $entries = [];

        foreach ($context->definitions->all() as $definition) {
            $descriptor = $definition->descriptor;

            if ($descriptor->scope === Scope::Singleton && ! $descriptor->lazy) {
                $entries[] = [$descriptor->order, $descriptor->class];
            }

            foreach ($descriptor->beans as $bean) {
                if ($bean->scope !== Scope::Singleton || $bean->lazy) {
                    continue;
                }

                // Null means the registrar bound nothing for this #[Bean] method at all (an
                // untyped return; or an anonymous competitor, which it rejects outright) — there
                // is no key to eagerly resolve, so skip rather than invent one.
                $key = $keys->keyFor($bean);
                if ($key === null) {
                    continue;
                }

                $entries[] = [$bean->order, $key];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $entry): string => $entry[1], $entries);
    }
}
