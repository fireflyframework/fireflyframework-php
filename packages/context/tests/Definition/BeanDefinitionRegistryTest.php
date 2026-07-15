<?php

declare(strict_types=1);

use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scope;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Tests\Fixtures\Cache;
use Firefly\Context\Tests\Fixtures\SomethingElse;

/**
 * @param  list<class-string>  $interfaces
 * @param  list<BeanDescriptor>  $beans
 */
function definitionDescriptor(string $class, array $interfaces = [], array $beans = [], int $order = 0): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: $order,
        qualifier: null,
        interfaces: $interfaces,
        beans: $beans,
    );
}

it('wraps a ComponentDescriptor and exposes its class via class(), defaulting conditions/source', function () {
    $descriptor = definitionDescriptor('App\CacheManager');
    $definition = new BeanDefinition($descriptor);

    expect($definition->descriptor)->toBe($descriptor)
        ->and($definition->class())->toBe('App\CacheManager')
        ->and($definition->conditions)->toBe([])
        ->and($definition->source)->toBe(DefinitionSource::User);
});

it('adds, removes, and lists definitions', function () {
    $registry = new BeanDefinitionRegistry;
    $a = new BeanDefinition(definitionDescriptor('App\A'));
    $b = new BeanDefinition(definitionDescriptor('App\B'));

    $registry->add($a);
    $registry->add($b);
    expect($registry->all())->toBe([$a, $b]);

    $registry->remove('App\A');
    expect($registry->all())->toBe([$b]);
});

it('reports containsType true when the type IS a definition class', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(definitionDescriptor('App\CacheManager')));

    expect($registry->containsType('App\CacheManager'))->toBeTrue();
});

it('reports containsType true when the type is a declared interface', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(definitionDescriptor('App\RedisCache', interfaces: [Cache::class])));

    expect($registry->containsType(Cache::class))->toBeTrue();
});

it('reports containsType true when the type is a #[Bean] method return type', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(definitionDescriptor('App\Config', beans: [
        new BeanDescriptor('cacheBean', Cache::class, null, Scope::Singleton, false, 0),
    ])));

    expect($registry->containsType(Cache::class))->toBeTrue();
});

it('reports containsType false when no definition makes the type available via any of the three routes', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(definitionDescriptor('App\Unrelated', interfaces: [SomethingElse::class])));

    expect($registry->containsType(Cache::class))->toBeFalse();
});

it('converts to a NEW ComponentManifest that round-trips descriptors losslessly and preserves order', function () {
    $registry = new BeanDefinitionRegistry;
    $descriptorA = definitionDescriptor('App\A', order: 5);
    $descriptorB = definitionDescriptor('App\B', order: 1);

    $registry->add(new BeanDefinition($descriptorA));
    $registry->add(new BeanDefinition($descriptorB));

    $manifest = $registry->toComponentManifest();

    expect($manifest)->toBeInstanceOf(ComponentManifest::class)
        ->and($manifest->components)->toBe([$descriptorA, $descriptorB]);
});

it('does not mutate a previously built ComponentManifest when the registry changes afterwards', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(new BeanDefinition(definitionDescriptor('App\A')));

    $manifest = $registry->toComponentManifest();
    $registry->add(new BeanDefinition(definitionDescriptor('App\B')));

    expect($manifest->components)->toHaveCount(1);
});
