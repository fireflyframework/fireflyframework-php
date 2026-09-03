<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\CompetingBeanFixtures\CachePing;
use Firefly\Context\Tests\CompetingBeanFixtures\CachePort;
use Firefly\Context\Tests\CompetingBeanFixtures\CacheProbe;
use Firefly\Context\Tests\CompetingBeanFixtures\ConcreteCache;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * TWO #[Bean] METHODS, ONE TYPE — the shape firefly/container started supporting when
 * ContainerRegistrar learned #[Primary]/#[Qualifier] for #[Bean] methods, and the shape every boot
 * pass in firefly/context silently got wrong.
 *
 * WHAT WAS BROKEN. ContainerRegistrar binds a CONTESTED type's competitors under their OWN #[Bean]
 * NAMES; the bare type key becomes an ALIAS of the #[Primary] winner, or — with no #[Primary] — a
 * factory that throws a NoUniqueBeanDefinition-style ConfigurationException. `$bean->returns` is
 * therefore no longer a registration key for any individual bean, yet all three instance-stage
 * passes keyed on it:
 *
 *  1. EagerSingletonsPass make()d `$bean->returns`, so with a #[Primary] only the WINNER was ever
 *     built (the "eager singleton" guarantee was violated, silently, for every named sibling —
 *     a bug that predates the container change and was merely exposed by it), and with no
 *     #[Primary] boot itself blew up on the ambiguity guard even though the application only ever
 *     injects those beans by #[Qualifier].
 *  2. RegisterBeanPostProcessorsPass extend()ed `$bean->returns`, which Illuminate resolves
 *     through the alias to the winner's key — so every named sibling escaped the
 *     BeanPostProcessor chain entirely: no #[PostConstruct], no DisposableBeanRegistry entry, and
 *     (in a real application) no #[Transactional] proxy, with no error anywhere.
 *  3. RegisterEventListenersPass registered one listener per contested TYPE and invoked it through
 *     that type key, so a sibling's #[AsEventListener] never fired — and with no #[Primary] the
 *     listener closure hit the ambiguity guard at DISPATCH time.
 *
 * Everything below runs the REAL ComponentScanner + ContextScanner over
 * packages/context/tests/CompetingBeanFixtures/ and the REAL ContainerRegistrar, because the bug is
 * precisely a disagreement between what the registrar BOUND and what the passes ASSUMED it bound —
 * a hand-built container would let that disagreement pass unnoticed.
 */

/**
 * @return array{0: ComponentManifest, 1: ContextManifest}
 */
function competingScan(): array
{
    /** @var array{0: ComponentManifest, 1: ContextManifest}|null $cached */
    static $cached = null;

    if ($cached === null) {
        $psr4 = ['Firefly\\Context\\Tests\\CompetingBeanFixtures\\' => dirname(__DIR__).'/CompetingBeanFixtures'];

        $cached = [
            new ComponentManifest((new ComponentScanner)->scan($psr4)),
            new ContextManifest((new ContextScanner)->scan($psr4)),
        ];
    }

    return $cached;
}

/**
 * Rebuilds the scanned manifest with every BeanDescriptor passed through $rewrite.
 *
 * The fixtures carry a real #[Primary] and no #[Lazy] (that is the documented, supported shape),
 * so the variants below are produced by rewriting the SCANNED descriptors rather than by
 * duplicating the whole fixture tree under a second namespace per variant. Only the #[Bean]
 * attributes under test change; everything else — including the ContextManifest, which is what
 * carries the #[PostConstruct]/#[AsEventListener] metadata — is the real scanner's output.
 * ComponentDescriptor/BeanDescriptor are `final readonly`, hence the rebuild rather than a mutation.
 *
 * @param  callable(BeanDescriptor): BeanDescriptor  $rewrite
 */
function competingRewrittenManifest(ComponentManifest $manifest, callable $rewrite): ComponentManifest
{
    return new ComponentManifest(array_map(
        static fn (ComponentDescriptor $component): ComponentDescriptor => new ComponentDescriptor(
            class: $component->class,
            stereotype: $component->stereotype,
            name: $component->name,
            scope: $component->scope,
            primary: $component->primary,
            order: $component->order,
            qualifier: $component->qualifier,
            interfaces: $component->interfaces,
            beans: array_map($rewrite, $component->beans),
            lazy: $component->lazy,
        ),
        $manifest->components,
    ));
}

function competingManifestWithoutPrimary(ComponentManifest $manifest): ComponentManifest
{
    return competingRewrittenManifest($manifest, static fn (BeanDescriptor $bean): BeanDescriptor => new BeanDescriptor(
        $bean->method,
        $bean->returns,
        $bean->name,
        $bean->scope,
        false,
        $bean->order,
        $bean->lazy,
    ));
}

/**
 * Marks the NON-#[Primary] competitor of each contested type #[Lazy], leaving its #[Primary]
 * sibling eager.
 */
function competingManifestWithLazySiblings(ComponentManifest $manifest): ComponentManifest
{
    return competingRewrittenManifest($manifest, static fn (BeanDescriptor $bean): BeanDescriptor => new BeanDescriptor(
        $bean->method,
        $bean->returns,
        $bean->name,
        $bean->scope,
        $bean->primary,
        $bean->order,
        ! $bean->primary,
    ));
}

/**
 * Boots the fixture application exactly as FlushDefinitionsPass would: ONE
 * ContainerRegistrar::register() over the same condition-filtered manifest the
 * BeanDefinitionRegistry then hands to the instance-stage passes.
 */
function competingContext(bool $withPrimary = true, bool $lazySiblings = false): BootContext
{
    [$components, $contextManifest] = competingScan();

    if (! $withPrimary) {
        $components = competingManifestWithoutPrimary($components);
    }

    if ($lazySiblings) {
        $components = competingManifestWithLazySiblings($components);
    }

    $container = new Container;
    $container->instance('events', new IlluminateDispatcher($container));
    (new ContainerRegistrar($container))->register($components);

    $definitions = new BeanDefinitionRegistry;
    foreach ($components->components as $component) {
        $definitions->add(new BeanDefinition($component));
    }

    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: $container,
        definitions: $definitions,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: $contextManifest,
    );
}

function competingProbe(BootContext $context): CacheProbe
{
    /** @var CacheProbe $probe */
    $probe = $context->container->make(CacheProbe::class);

    return $probe;
}

/**
 * The real instance-stage order: BeanPostProcessors (700), EventListeners (800), EagerSingletons
 * (900). Running them out of order would make several assertions below vacuously true.
 */
function competingBoot(BootContext $context): void
{
    (new RegisterBeanPostProcessorsPass)->run($context);
    (new RegisterEventListenersPass)->run($context);
    (new EagerSingletonsPass)->run($context);
}

it('eagerly instantiates EVERY competing #[Bean], not just the #[Primary] winner', function () {
    $context = competingContext();

    competingBoot($context);

    // Both halves of the contested CachePort, and both halves of the contested ConcreteCache.
    expect(competingProbe($context)->matching('construct:'))
        ->toEqualCanonicalizing(['construct:memory', 'construct:redis', 'construct:near', 'construct:far']);
});

it('still honours #[Lazy] on a competing #[Bean] — resolving by name must not resolve everything', function () {
    $context = competingContext(lazySiblings: true);

    competingBoot($context);
    $probe = competingProbe($context);

    // Only the eager (#[Primary]) half of each contested pair. Resolving competitors by their own
    // names is what makes the eager guarantee hold for ALL of them; it must not quietly promote a
    // #[Lazy] sibling to eager along the way.
    expect($probe->matching('construct:'))->toEqualCanonicalizing(['construct:memory', 'construct:near']);

    // And the #[Lazy] sibling still builds — with its extender intact — on first real use.
    /** @var CachePort $lazySibling */
    $lazySibling = $context->container->make('redisCache');

    expect($lazySibling->name())->toBe('redis')
        ->and($probe->matching('postConstruct:'))
        ->toEqualCanonicalizing(['postConstruct:memory', 'postConstruct:redis']);
});

it('boots a contested type that has NO #[Primary] instead of tripping the ambiguity guard', function () {
    $context = competingContext(withPrimary: false);

    // The bare type key is bound to a throwing guard factory here. An application that injects
    // these beans only by #[Qualifier] is perfectly valid, so boot must never touch that key.
    competingBoot($context);

    expect(competingProbe($context)->matching('construct:'))
        ->toEqualCanonicalizing(['construct:memory', 'construct:redis', 'construct:near', 'construct:far']);
});

it('runs the BeanPostProcessor chain for every competing #[Bean], threading the DECLARED TYPE as $declaredClass', function () {
    $context = competingContext();

    competingBoot($context);

    // $declaredClass stays CachePort::class for both: a bean NAME is a container key, not a class,
    // and TransactionalBeanPostProcessor calls class_exists() on what it receives here.
    expect(competingProbe($context)->matching('bpp:'))->toEqualCanonicalizing([
        'bpp:before:memory:'.CachePort::class,
        'bpp:after:memory:'.CachePort::class,
        'bpp:before:redis:'.CachePort::class,
        'bpp:after:redis:'.CachePort::class,
    ]);
});

it('fires #[PostConstruct] on every competing #[Bean], not only the #[Primary] one', function () {
    $context = competingContext();

    competingBoot($context);

    expect(competingProbe($context)->matching('postConstruct:'))
        ->toEqualCanonicalizing(['postConstruct:memory', 'postConstruct:redis']);
});

it('registers each competing bean listener exactly once, for both the concrete-return and interface-return shapes', function () {
    $context = competingContext();

    competingBoot($context);
    $probe = competingProbe($context);
    $probe->events = [];

    $context->container->make('events')->dispatch(new CachePing);

    // interface return (CachePort) — recovered by the late-bound extender path;
    // concrete return (ConcreteCache) — found by RegisterEventListenersPass's own sweep.
    // EXACTLY ONCE each: a second entry for any of them would be a duplicate registration.
    expect($probe->matching('listener:'))
        ->toEqualCanonicalizing(['listener:memory', 'listener:redis', 'listener:near', 'listener:far']);
});

it('dispatches to competing bean listeners without touching the contested type key when there is no #[Primary]', function () {
    $context = competingContext(withPrimary: false);

    competingBoot($context);
    $probe = competingProbe($context);
    $probe->events = [];

    // The listener closures must resolve each bean by its OWN key. Resolving the contested type
    // instead would throw the ambiguity ConfigurationException here, at dispatch time.
    $context->container->make('events')->dispatch(new CachePing);

    expect($probe->matching('listener:'))
        ->toEqualCanonicalizing(['listener:memory', 'listener:redis', 'listener:near', 'listener:far']);
});

it('keeps a contested type key resolving to the #[Primary] winner, with each sibling a distinct singleton', function () {
    $context = competingContext();

    competingBoot($context);

    // The counterpart to everything above: resolving competitors by their OWN keys must not
    // disturb the GROUP key. ConcreteCache is contested, so its type key is an alias of the
    // #[Primary] 'nearCache' — it still resolves, still resolves to the winner, and the named
    // sibling is still a SEPARATE singleton rather than the winner handed back twice.
    //
    // (The uncontested shape — where the key IS the return type — is unchanged by this work and
    // stays pinned by BeanProducedInterfaceListenerTest, DedupeKeyTest and IntegrationTest.)
    /** @var ConcreteCache $viaType */
    $viaType = $context->container->make(ConcreteCache::class);
    /** @var ConcreteCache $viaPrimaryName */
    $viaPrimaryName = $context->container->make('nearCache');
    /** @var ConcreteCache $viaSiblingName */
    $viaSiblingName = $context->container->make('farCache');

    expect($viaType->tag())->toBe('near');
    expect($viaPrimaryName)->toBe($viaType);
    expect($viaSiblingName)->not->toBe($viaType);
    expect($viaSiblingName->tag())->toBe('far');
});
