<?php

declare(strict_types=1);

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\StaleDefinitionReport;

/**
 * The door every definition comes through, and the one place a compiled manifest's class name is checked.
 *
 * EagerSingletonsPass has skipped a definition whose class is gone since 26.09.1, and it was still not
 * enough: it guards the pass it is written in, while ContainerRegistrar, RegisterBeanPostProcessorsPass,
 * InfrastructureStartPass and RegisterEventListenersPass each read a class straight off the same manifest
 * with nothing between them and make(). Filtering here closes all of them at once — see
 * StaleManifestWiringTest for the proof on the wiring, and packages/cli's StaleManifestRecoveryTest for
 * the command that has to survive it.
 *
 * The asymmetry these cases pin down is the point: the drop happens ONLY in a boot that exists to repair
 * the cache. Every other boot keeps the definition and fails exactly as loudly as before, because a class
 * that has gone missing in a served process is a broken deployment, not a stale cache.
 */
function staleFilterDefinition(string $class): BeanDefinition
{
    return new BeanDefinition(new ComponentDescriptor(
        class: $class,
        stereotype: 'component',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    ));
}

final class StaleFilterLiveFixture {}

it('drops a definition whose class no longer exists, and records which one', function () {
    $report = new StaleDefinitionReport;
    $registry = new BeanDefinitionRegistry($report, dropMissingClasses: true);

    $registry->add(staleFilterDefinition('App\\Deleted\\GoneProvider'));
    $registry->add(staleFilterDefinition(StaleFilterLiveFixture::class));

    expect(array_map(
        static fn (BeanDefinition $d): string => $d->class(),
        $registry->all(),
    ))->toBe([StaleFilterLiveFixture::class])
        ->and($report->classes())->toBe(['App\\Deleted\\GoneProvider'])
        ->and($report->isEmpty())->toBeFalse();
});

// The dropped entry must not reach the container registrar either — toComponentManifest() is what
// FlushDefinitionsPass hands over, and wireInterfaces() binding a contract to a missing class is the
// crash this whole change exists to remove.
it('never puts a dropped definition into the ComponentManifest it hands the registrar', function () {
    $registry = new BeanDefinitionRegistry(new StaleDefinitionReport, dropMissingClasses: true);

    $registry->add(staleFilterDefinition('App\\Deleted\\GoneProvider'));
    $registry->add(staleFilterDefinition(StaleFilterLiveFixture::class));

    expect(array_map(
        static fn (ComponentDescriptor $d): string => $d->class,
        $registry->toComponentManifest()->components,
    ))->toBe([StaleFilterLiveFixture::class]);
});

// A repair boot is not a licence to lose beans that are perfectly fine. Nothing else changes.
it('keeps every definition whose class does exist', function () {
    $report = new StaleDefinitionReport;
    $registry = new BeanDefinitionRegistry($report, dropMissingClasses: true);

    $registry->add(staleFilterDefinition(StaleFilterLiveFixture::class));

    expect($registry->all())->toHaveCount(1)
        ->and($report->isEmpty())->toBeTrue();
});

/*
 | The default, which is what every boot that is NOT firefly:cache or firefly:clear gets: the manifest is
 | trusted exactly as it was before this change. A missing class in a served process means the deployment
 | is wrong — a truncated artifact, a classmap built from another tree — and dropping the definition there
 | would let the application serve traffic with an interface quietly rebound to whichever implementation
 | survived. That wrong answer, given in silence, is worse than the boot failure being removed.
 */
it('keeps a definition whose class is missing when this is not a repair boot', function () {
    $report = new StaleDefinitionReport;
    $registry = new BeanDefinitionRegistry($report);

    $registry->add(staleFilterDefinition('App\\Deleted\\GoneProvider'));

    expect($registry->all())->toHaveCount(1)
        ->and($report->isEmpty())->toBeTrue();
});

// The same class can reach the registry as a user definition and again from an auto-configuration. The
// developer wants the list of files to stop worrying about, not a tally of registry writes.
it('names a dropped class once however many definitions carried it', function () {
    $report = new StaleDefinitionReport;
    $registry = new BeanDefinitionRegistry($report, dropMissingClasses: true);

    $registry->add(staleFilterDefinition('App\\Deleted\\GoneProvider'));
    $registry->add(staleFilterDefinition('App\\Deleted\\GoneProvider'));

    expect($report->classes())->toBe(['App\\Deleted\\GoneProvider']);
});

it('reports nothing at all when the manifest is clean', function () {
    $report = new StaleDefinitionReport;
    $registry = new BeanDefinitionRegistry($report, dropMissingClasses: true);

    $registry->add(staleFilterDefinition(StaleFilterLiveFixture::class));

    expect($report->isEmpty())->toBeTrue()
        ->and($report->classes())->toBe([]);
});
