<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * A compiled manifest goes stale the moment a class is deleted or renamed, which is an ordinary thing to do
 * while developing.
 *
 * The consequence used to be catastrophic AND unrecoverable. The manifest still named the class,
 * EagerSingletonsPass resolved it, the container threw "Target class does not exist" — and both commands
 * that repair the situation, firefly:cache and firefly:clear, died with the SAME error, because each must
 * boot the application before it can rewrite or delete the manifest. Deleting one controller bricked the
 * application, and the only escape was to remove bootstrap/cache/firefly by hand.
 *
 * Reproduced end to end before the fix: `/`, `/actuator` and `/firefly` all answered 500, and
 * `php artisan firefly:cache` exited 1 without writing anything.
 */
function stalePassContext(BeanDefinitionRegistry $definitions): BootContext
{
    $container = new Container;
    $config = new Config(new Repository([]));
    $profiles = new Profiles(['default']);

    return new BootContext(
        container: $container,
        definitions: $definitions,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: new ContextManifest([]),
    );
}

function staleDefinition(string $class): BeanDefinition
{
    return new BeanDefinition(
        descriptor: new ComponentDescriptor(
            class: $class,
            stereotype: 'component',
            name: null,
            scope: Scope::Singleton,
            primary: false,
            order: 0,
            qualifier: null,
            interfaces: [],
            beans: [],
        ),
        source: DefinitionSource::User,
    );
}

it('boots past a definition whose class no longer exists', function () {
    $definitions = new BeanDefinitionRegistry;
    $definitions->add(staleDefinition('App\\Deleted\\GoneController'));

    $context = stalePassContext($definitions);

    // The whole point: this must NOT throw. Before the guard it raised
    // BindingResolutionException("Target class [App\Deleted\GoneController] does not exist.").
    (new EagerSingletonsPass)->run($context);

    expect($context->container->resolved('App\\Deleted\\GoneController'))->toBeFalse();
});

// Only a MISSING class is tolerated. A class that exists and genuinely cannot be built is a real defect, and
// failing fast at boot is exactly right for it — a guard that swallowed those would hide broken code.
it('still fails fast when a class that DOES exist cannot be constructed', function () {
    $definitions = new BeanDefinitionRegistry;
    $definitions->add(staleDefinition(UnconstructableFixture::class));

    expect(fn () => (new EagerSingletonsPass)->run(stalePassContext($definitions)))
        ->toThrow(BindingResolutionException::class);
});

it('still resolves the definitions around a stale one', function () {
    $definitions = new BeanDefinitionRegistry;
    $definitions->add(staleDefinition('App\\Deleted\\GoneController'));
    $definitions->add(staleDefinition(ConstructableFixture::class));

    $context = stalePassContext($definitions);
    (new EagerSingletonsPass)->run($context);

    expect($context->container->resolved(ConstructableFixture::class))->toBeTrue();
});

final class ConstructableFixture {}

/** Constructible only if the container can satisfy an interface nothing binds — which nothing does. */
final class UnconstructableFixture
{
    public function __construct(public readonly NeverBindableContract $missing) {}
}

interface NeverBindableContract {}
