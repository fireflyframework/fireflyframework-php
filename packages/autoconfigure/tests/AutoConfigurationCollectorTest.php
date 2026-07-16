<?php

declare(strict_types=1);

use Firefly\AutoConfigure\AutoConfigurationCandidate;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;

function collectorCandidate(string $provider): AutoConfigurationCandidate
{
    return new AutoConfigurationCandidate($provider, "/tmp/{$provider}-c.php", "/tmp/{$provider}-x.php");
}

function collectorDefinition(string $class): BeanDefinition
{
    return new BeanDefinition(
        new ComponentDescriptor(
            class: $class, stereotype: 'configuration', name: null, scope: Scope::Singleton,
            primary: false, order: 0, qualifier: null, interfaces: [], beans: [],
        ),
        [], DefinitionSource::AutoConfiguration, [],
    );
}

it('collects candidates as an insertion-ordered set deduped by provider FQCN', function () {
    $collector = new AutoConfigurationCollector;
    $collector->add(collectorCandidate('A'));
    $collector->add(collectorCandidate('B'));
    $collector->add(collectorCandidate('A')); // duplicate provider — must not appear twice

    expect(array_map(fn ($c) => $c->provider, $collector->all()))->toBe(['A', 'B']);
});

it('dedupes on provider FQCN alone, not on the whole candidate value, and keeps the last write', function () {
    $collector = new AutoConfigurationCollector;

    // Same provider, but every other field differs — a whole-value key would treat these as
    // distinct entries and keep both. A provider-only key must collapse them to one.
    $a1 = new AutoConfigurationCandidate('SomeProvider', '/tmp/comp-1.php', '/tmp/ctx-1.php');
    $a2 = new AutoConfigurationCandidate('SomeProvider', '/tmp/comp-2.php', '/tmp/ctx-2.php');

    $collector->add($a1);
    $collector->add($a2);

    $all = $collector->all();

    // If dedupe were keyed on the whole candidate (e.g. provider + both manifest paths), $a1 and
    // $a2 differ in every non-provider field and both would survive, making this count 2.
    expect($all)->toHaveCount(1);

    // Last-write-wins: the survivor must be $a2 (its paths), not $a1.
    expect($all[0]->provider)->toBe('SomeProvider');
    expect($all[0]->componentManifestPath)->toBe('/tmp/comp-2.php');
    expect($all[0]->contextManifestPath)->toBe('/tmp/ctx-2.php');
});

it('keeps candidates from two different providers as two distinct entries', function () {
    $collector = new AutoConfigurationCollector;

    $collector->add(new AutoConfigurationCandidate('ProviderOne', '/tmp/one-c.php', '/tmp/one-x.php'));
    $collector->add(new AutoConfigurationCandidate('ProviderTwo', '/tmp/two-c.php', '/tmp/two-x.php'));

    expect($collector->all())->toHaveCount(2);
});

it('stashes and returns assembled definitions verbatim', function () {
    $collector = new AutoConfigurationCollector;
    expect($collector->assembledDefinitions())->toBe([]);

    $defs = [collectorDefinition('App\\One'), collectorDefinition('App\\Two')];
    $collector->setAssembledDefinitions($defs);

    expect($collector->assembledDefinitions())->toBe($defs);
});
