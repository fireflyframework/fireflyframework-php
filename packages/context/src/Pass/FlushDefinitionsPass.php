<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Config\Registrar\ConfigRegistrar;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;

/**
 * The single flush point of the definition stage — THE MOST IMPORTANT PASS IN THE MILESTONE.
 *
 * Passes 300 (ConditionPassOnePass) and 500 (ConditionPassTwoPass) have already removed every
 * definition whose conditions didn't match. This pass builds exactly ONE condition-filtered
 * ComponentManifest from what remains and hands it to M2's ContainerRegistrar::register() —
 * EXACTLY ONCE, ever.
 *
 * INVARIANT 6 (design decisions doc): ContainerRegistrar carries a per-container sentinel
 * ("firefly.container.registered") — a SECOND register() call is a silent no-op, not an error.
 * Conditions MUST therefore filter the manifest upstream (they do, in passes 300/500), and this
 * pass MUST be the only call site. If the kernel ever runs this pass twice (e.g. a bug in phase
 * idempotency), the sentinel makes the repeat a safe no-op rather than a duplicate registration —
 * but that safety net is not a license to call register() more than once on purpose.
 *
 * FACADE-MANIFEST IDENTITY: the SAME $manifest object built above is used both for
 * ContainerRegistrar::register() and to construct the Container facade. The facade's getAll()
 * builds its #[Order] map from the manifest it was constructed with — handing it a different
 * (e.g. unfiltered) manifest would let order/exposure semantics silently drift from what was
 * actually registered. Passing the identical object removes any possibility of that drift.
 *
 * CONFIG PROPERTIES: M4 has no #[ConfigProperties] scanner wired into the boot pipeline yet — the
 * manifest of already-scanned DTOs is accepted via constructor injection (empty by default), the
 * same "accept pre-scanned data, do not fake a scan" pattern as UserConfigurationsPass. Wiring
 * ConfigRegistrar here (rather than skipping it) still closes M2's ValueResolver seam: it installs
 * the config-backed ConfigValueResolver in place of firefly/container's DefaultValueResolver, even
 * when the DTO list itself is empty. Its ordering relative to ContainerRegistrar is proven
 * order-independent in both directions by M3 (config-first: ContainerRegistrar's
 * `if (!bound)` backs off; container-first: ConfigRegistrar's `instance()` always rebinds), so it
 * is called here, after ContainerRegistrar, purely to keep one flush point — not because order
 * matters.
 */
final class FlushDefinitionsPass implements BootPass
{
    public function __construct(
        private readonly ConfigPropertiesManifest $configProperties = new ConfigPropertiesManifest([]),
    ) {}

    public function phase(): BootPhase
    {
        return BootPhase::FlushDefinitions;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        // The ONE condition-filtered manifest. Never rebuild or re-derive a second one below —
        // both writes in this method must observe the exact same object.
        $manifest = $context->definitions->toComponentManifest();

        (new ContainerRegistrar($context->container))->register($manifest);

        (new ConfigRegistrar($context->container, $context->config))->register($this->configProperties);

        $context->container->instance(
            FireflyContainer::class,
            new FireflyContainer($context->container, $manifest),
        );
    }
}
