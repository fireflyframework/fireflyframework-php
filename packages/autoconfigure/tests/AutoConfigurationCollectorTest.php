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

it('stashes and returns assembled definitions verbatim', function () {
    $collector = new AutoConfigurationCollector;
    expect($collector->assembledDefinitions())->toBe([]);

    $defs = [collectorDefinition('App\\One'), collectorDefinition('App\\Two')];
    $collector->setAssembledDefinitions($defs);

    expect($collector->assembledDefinitions())->toBe($defs);
});
