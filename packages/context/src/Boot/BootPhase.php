<?php

declare(strict_types=1);

namespace Firefly\Context\Boot;

/**
 * The ordered boot pipeline (spec §2.2, as amended by the M4 design decisions doc).
 *
 * Ordinals are GAPPED so a later milestone can slot a phase between two existing ones without renumbering.
 * Phases up to FlushDefinitions are the DEFINITION stage (pure data, no container writes); everything after
 * operates on INSTANCES.
 *
 * Three deliberate deltas vs the spec draft, each fixing a real bug:
 *  - BeanPostProcessors (700) precedes InfrastructureStart: the spec resolved Lifecycle beans before any
 *    extender existed, so they permanently escaped post-processing.
 *  - EventListeners (800) precedes EagerSingletons: the spec registered listeners after eager singletons, so
 *    an event published from a #[PostConstruct] reached nobody, silently.
 *  - FlushDefinitions (650) is new — the honest name for the single ContainerRegistrar write.
 */
enum BootPhase: int
{
    case ConfigAndProfiles = 100;      // firefly/config
    case AutoConfigDiscovery = 200;    // M5 seam
    case ConditionPassOne = 300;       // registry-independent conditions
    case UserConfigurations = 400;     // user #[Configuration]/#[Bean] definitions
    case ConditionPassTwo = 500;       // bean-dependent conditions
    case AutoConfigurations = 600;     // M5 seam
    case FlushDefinitions = 650;       // the ONE ContainerRegistrar::register() write
    case BeanPostProcessors = 700;     // install composite extenders
    case EventListeners = 800;         // register #[AsEventListener]s
    case InfrastructureStart = 850;    // Lifecycle::start() fail-fast
    case EagerSingletons = 900;        // resolve eager singletons, #[Order]-sorted from the manifest
    case WiringPasses = 1000;          // M6/M7/M10 seams
    case ContextRefreshed = 1200;      // fire lifecycle events
}
