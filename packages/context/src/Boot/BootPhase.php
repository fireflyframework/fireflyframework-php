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
 * Four deliberate deltas vs the spec draft, each fixing a real bug:
 *  - BeanPostProcessors (700) precedes InfrastructureStart: the spec resolved Lifecycle beans before any
 *    extender existed, so they permanently escaped post-processing.
 *  - EventListeners (800) precedes EagerSingletons: the spec registered listeners after eager singletons, so
 *    an event published from a #[PostConstruct] reached nobody, silently.
 *  - FlushDefinitions (650) is new — the honest name for the single ContainerRegistrar write.
 *  - EACH CONDITION PASS FOLLOWS ITS OWN DEFINITION SOURCE (UserConfigurations before ConditionPassOne;
 *    AutoConfigurations before ConditionPassTwo) — see below. An earlier shipped ordering ran both
 *    condition passes BEFORE the definitions they were meant to filter, so their conditions were silently
 *    never evaluated; a capstone integration test (not a unit test — see below) caught it.
 *
 * WHY EACH CONDITION PASS MUST FOLLOW ITS DEFINITION SOURCE — read this before "tidying up" the order:
 *
 *   300 UserConfigurations  → 400 ConditionPassOne   → 500 AutoConfigurations → 600 ConditionPassTwo
 *
 * ConditionPassOnePass only evaluates whatever is ALREADY in the BeanDefinitionRegistry when it runs.
 * A condition pass placed BEFORE its definitions exist is not "evaluated early" — it is evaluated against
 * an empty/partial registry, so the condition vacuously passes and the definition survives no matter what
 * the condition actually says. This is exactly what shipped once:
 *   - ConditionPassOne (then 300) ran before UserConfigurations (then 400): every user
 *     #[ConditionalOnProperty]/#[ConditionalOnClass]/#[ConditionalOnMissingClass]/#[ConditionalOnProfile]
 *     was decorative — caught by IntegrationTest, not by any per-pass unit test (each of those tested a
 *     pass in isolation against a registry that happened to be pre-populated, which the real pipeline never
 *     guarantees).
 *   - AutoConfigurations (then 600) ran after ConditionPassTwo (then 500): auto-configuration conditions,
 *     including #[ConditionalOnMissingBean] — the entire mechanism a starter uses to back off when the user
 *     already supplies a bean — would NEVER be evaluated. Latent in M4 (no starter ships yet) but would have
 *     broken every M5 auto-configuration on day one.
 *
 * This ordering is what Spring itself does: parse user @Configuration classes — evaluating @Conditional
 * DURING the parse (ConfigurationPhase.PARSE_CONFIGURATION, this is ConditionPassOne's role) — then import
 * auto-configurations LAST, evaluating their conditions (ConfigurationPhase.REGISTER_BEAN, ConditionPassTwo's
 * role) against a registry that already holds every surviving user bean. See ConditionPassOnePass and
 * ConditionPassTwoPass for the exact semantics each pass now applies.
 */
enum BootPhase: int
{
    case ConfigAndProfiles = 100;      // firefly/config
    case AutoConfigDiscovery = 200;    // M5 seam: DISCOVER candidates only — do NOT add definitions here
    case UserConfigurations = 300;     // user #[Configuration]/#[Bean] definitions enter the registry
    case ConditionPassOne = 400;       // PARSE_CONFIGURATION: evaluate conditions over USER definitions
    case AutoConfigurations = 500;     // M5 seam: auto-configuration definitions enter the registry
    case ConditionPassTwo = 600;       // REGISTER_BEAN: evaluate ALL conditions over AUTO-CONFIG definitions
    case FlushDefinitions = 650;       // the ONE ContainerRegistrar::register() write
    case BeanPostProcessors = 700;     // install composite extenders
    case EventListeners = 800;         // register #[AsEventListener]s
    case InfrastructureStart = 850;    // Lifecycle::start() fail-fast
    case EagerSingletons = 900;        // resolve eager singletons, #[Order]-sorted from the manifest
    case WiringPasses = 1000;          // M6/M7/M10 seams
    case ContextRefreshed = 1200;      // fire lifecycle events
}
