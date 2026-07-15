<?php

declare(strict_types=1);

use Firefly\Context\Boot\BootPhase;

it('orders phases: definitions, then the single flush, then instances', function () {
    expect(BootPhase::ConfigAndProfiles->value)->toBeLessThan(BootPhase::ConditionPassOne->value)
        ->and(BootPhase::ConditionPassOne->value)->toBeLessThan(BootPhase::UserConfigurations->value)
        ->and(BootPhase::UserConfigurations->value)->toBeLessThan(BootPhase::ConditionPassTwo->value)
        ->and(BootPhase::ConditionPassTwo->value)->toBeLessThan(BootPhase::FlushDefinitions->value)
        // BPP extenders MUST be installed before any bean is resolved (Lifecycle/eager),
        // else those beans permanently escape post-processing.
        ->and(BootPhase::FlushDefinitions->value)->toBeLessThan(BootPhase::BeanPostProcessors->value)
        // Listeners must be registered before eager singletons, else an event published
        // from a #[PostConstruct] reaches nobody, silently.
        ->and(BootPhase::BeanPostProcessors->value)->toBeLessThan(BootPhase::EventListeners->value)
        ->and(BootPhase::EventListeners->value)->toBeLessThan(BootPhase::InfrastructureStart->value)
        ->and(BootPhase::InfrastructureStart->value)->toBeLessThan(BootPhase::EagerSingletons->value)
        ->and(BootPhase::EagerSingletons->value)->toBeLessThan(BootPhase::WiringPasses->value)
        ->and(BootPhase::WiringPasses->value)->toBeLessThan(BootPhase::ContextRefreshed->value);
});

it('leaves numeric gaps so a later milestone can insert a phase without renumbering', function () {
    $values = array_map(static fn (BootPhase $p): int => $p->value, BootPhase::cases());
    sort($values);
    for ($i = 1; $i < count($values); $i++) {
        expect($values[$i] - $values[$i - 1])->toBeGreaterThan(1);
    }
});
