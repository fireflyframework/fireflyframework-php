<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Qualifier;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scope;
use Firefly\Container\Tests\Fixtures\ApiToken;
use Firefly\Container\Tests\Fixtures\Cache;
use Firefly\Container\Tests\Fixtures\CacheConsumer;
use Firefly\Container\Tests\Fixtures\Clock;
use Firefly\Container\Tests\Fixtures\Gadget;
use Firefly\Container\Tests\Fixtures\MemoryCache;
use Firefly\Container\Tests\Fixtures\RedisCache;
use Firefly\Container\Tests\Fixtures\Stamp;
use Firefly\Kernel\Exception\Framework\BeanNotFoundException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Container\Container as IlluminateContainer;

/**
 * Registers the shared fixture scan. Named distinctly from ContainerRegistrarTest's
 * registeredContainer() because Pest test files share one global function namespace.
 */
function beanFixtureContainer(): IlluminateContainer
{
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);
    $illuminate = new IlluminateContainer;
    (new ContainerRegistrar($illuminate))->register(new ComponentManifest($components));

    return $illuminate;
}

/**
 * Builds a one-component manifest around hand-written BeanDescriptors. The
 * registration-time collision guards are about descriptor SHAPE, so the beans
 * are declared directly rather than round-tripped through a fixture class.
 *
 * @param  list<BeanDescriptor>  $beans
 */
function beanCollisionManifest(string $class, array $beans): ComponentManifest
{
    return new ComponentManifest([
        new ComponentDescriptor(
            class: $class,
            stereotype: 'configuration',
            name: null,
            scope: Scope::Singleton,
            primary: false,
            order: 0,
            qualifier: null,
            interfaces: [],
            beans: $beans,
        ),
    ]);
}

// A hand-built #[Configuration] stand-in for the collision guards. Kept OUT of
// tests/Fixtures/ so the shared ComponentScanner never picks it up.
final class CollisionConfig
{
    public function first(): Gadget
    {
        return new Gadget;
    }

    public function second(): Gadget
    {
        return new Gadget;
    }
}

// --- (1) named beans of one type ---------------------------------------------

it('binds every competing #[Bean] under its own name instead of collapsing them onto one alias', function () {
    $c = beanFixtureContainer();

    // Both names used to be aliases of Cache::class, so BOTH resolved to
    // whichever factory was registered last. They must now be distinct beans.
    /** @var Cache $memory */
    $memory = $c->make('memoryCache');
    /** @var Cache $redis */
    $redis = $c->make('redisCache');

    expect($memory)->toBeInstanceOf(MemoryCache::class)
        ->and($redis)->toBeInstanceOf(RedisCache::class)
        ->and($memory->label())->toBe('memory')
        ->and($redis->label())->toBe('redis');
});

it('picks the type-level default from #[Primary] on a #[Bean] method', function () {
    $c = beanFixtureContainer();

    // BeanDescriptor::$primary was read NOWHERE before this fix.
    expect($c->make(Cache::class))->toBeInstanceOf(MemoryCache::class);
});

it('keeps one singleton behind a bean name and the type default it wins', function () {
    $c = beanFixtureContainer();

    // The type key must ALIAS the winning bean rather than re-bind the factory:
    // a second binding would make Cache::class and 'memoryCache' two distinct
    // singletons of the same #[Bean] method. Bean names are plain container
    // keys, so make() reports `mixed` and each resolution is narrowed here.
    /** @var Cache $byType */
    $byType = $c->make(Cache::class);
    /** @var Cache $memory */
    $memory = $c->make('memoryCache');
    /** @var Cache $redis */
    $redis = $c->make('redisCache');

    /** @var Cache $memoryAgain */
    $memoryAgain = $c->make('memoryCache');
    /** @var Cache $redisAgain */
    $redisAgain = $c->make('redisCache');

    expect($byType)->toBe($memory)
        ->and($memoryAgain)->toBe($memory)
        ->and($redisAgain)->toBe($redis)
        ->and($memory)->not->toBe($redis);
});

it('preserves the single-bean-per-type path: the type binding and its name alias stay one instance', function () {
    $c = beanFixtureContainer();

    /** @var Clock $clock */
    $clock = $c->make(Clock::class);
    /** @var Clock $named */
    $named = $c->make('utcClock');

    expect($clock->zone)->toBe('UTC')
        ->and($named)->toBe($clock);
});

it('leaves a contested type resolvable by name and answers the bare type with a NoUniqueBeanDefinition error', function () {
    $c = beanFixtureContainer();

    // Three named #[Bean] methods return Gadget and none is #[Primary].
    /** @var Gadget $edge */
    $edge = $c->make('edgeGadget');
    /** @var Gadget $lazy */
    $lazy = $c->make('lazyGadget');
    /** @var Gadget $eager */
    $eager = $c->make('eagerGadget');

    expect($edge)->toBeInstanceOf(Gadget::class)
        ->and($lazy)->toBeInstanceOf(Gadget::class)
        ->and($eager)->toBeInstanceOf(Gadget::class)
        ->and($edge)->not->toBe($lazy)
        // The type stays BOUND so #[ConditionalOnMissingBean] still sees that a
        // bean of this type exists — resolving it is what fails, loudly.
        ->and($c->bound(Gadget::class))->toBeTrue();

    expect(fn () => $c->make(Gadget::class))
        ->toThrow(ConfigurationException::class, 'No unique bean of type');
});

// --- (1b) registration-time guards -------------------------------------------

it('refuses to register two anonymous #[Bean] methods returning the same type', function () {
    $manifest = beanCollisionManifest(CollisionConfig::class, [
        new BeanDescriptor('first', Gadget::class, null, Scope::Singleton, false, 0),
        new BeanDescriptor('second', Gadget::class, null, Scope::Singleton, false, 0),
    ]);

    expect(fn () => (new ContainerRegistrar(new IlluminateContainer))->register($manifest))
        ->toThrow(ConfigurationException::class, 'CollisionConfig::second()');
});

it('refuses to register two #[Bean] methods of one type under the same name', function () {
    $manifest = beanCollisionManifest(CollisionConfig::class, [
        new BeanDescriptor('first', Gadget::class, 'gadget', Scope::Singleton, false, 0),
        new BeanDescriptor('second', Gadget::class, 'gadget', Scope::Singleton, false, 0),
    ]);

    expect(fn () => (new ContainerRegistrar(new IlluminateContainer))->register($manifest))
        ->toThrow(ConfigurationException::class, "Duplicate #[Bean] name 'gadget'");
});

it('refuses to register a competing #[Bean] named after the contested type itself', function () {
    // Gadget::class as a bean name IS the contested type key, which the group owns:
    // binding the bean there and then pointing the type at the #[Primary] winner would
    // leave the first bean declared but unreachable.
    $manifest = beanCollisionManifest(CollisionConfig::class, [
        new BeanDescriptor('first', Gadget::class, Gadget::class, Scope::Singleton, false, 0),
        new BeanDescriptor('second', Gadget::class, 'other', Scope::Singleton, true, 0),
    ]);

    expect(fn () => (new ContainerRegistrar(new IlluminateContainer))->register($manifest))
        ->toThrow(ConfigurationException::class, 'named after the very type it competes for');
});

it('refuses to register more than one #[Primary] #[Bean] for the same type', function () {
    $manifest = beanCollisionManifest(CollisionConfig::class, [
        new BeanDescriptor('first', Gadget::class, 'a', Scope::Singleton, true, 0),
        new BeanDescriptor('second', Gadget::class, 'b', Scope::Singleton, true, 0),
    ]);

    expect(fn () => (new ContainerRegistrar(new IlluminateContainer))->register($manifest))
        ->toThrow(ConfigurationException::class, 'more than one #[Primary]');
});

it('still registers a lone anonymous #[Bean] exactly as before', function () {
    $manifest = beanCollisionManifest(CollisionConfig::class, [
        new BeanDescriptor('first', Gadget::class, null, Scope::Singleton, false, 0),
    ]);

    $c = new IlluminateContainer;
    (new ContainerRegistrar($c))->register($manifest);

    expect($c->make(Gadget::class))->toBeInstanceOf(Gadget::class)
        ->and($c->make(Gadget::class))->toBe($c->make(Gadget::class));
});

// --- (2) #[Qualifier] on a constructor parameter ------------------------------

it('resolves a constructor #[Qualifier] by bean name, not by parameter type', function () {
    $c = beanFixtureContainer();

    /** @var CacheConsumer $consumer */
    $consumer = $c->make(CacheConsumer::class);

    // Cache::class alone resolves to the #[Primary] MemoryCache, so a RedisCache
    // here can only have come from reading #[Qualifier('redisCache')].
    expect($consumer->cache)->toBeInstanceOf(RedisCache::class)
        ->and($consumer->cache)->toBe($c->make('redisCache'));
});

it('reports an unknown #[Qualifier] name instead of silently falling back to the type', function () {
    $c = beanFixtureContainer();

    $broken = new class(new MemoryCache)
    {
        public function __construct(
            #[Qualifier('noSuchCache')]
            public readonly Cache $cache,
        ) {}
    };

    expect(fn () => $c->make($broken::class))
        ->toThrow(BeanNotFoundException::class, 'noSuchCache');
});

it('resolves a #[Qualifier] on a #[Bean] factory method parameter too', function () {
    $c = beanFixtureContainer();

    $config = new class
    {
        #[Bean]
        public function probe(#[Qualifier('redisCache')] Cache $cache): Cache
        {
            return $cache;
        }
    };

    expect($c->call([$config, 'probe']))->toBeInstanceOf(RedisCache::class);
});

// --- (3) #[Bean] discovery beyond the literal #[Configuration] stereotype ------

it('registers #[Bean] methods declared on a custom #[Configuration] subclass stereotype', function () {
    $c = beanFixtureContainer();

    /** @var ApiToken $token */
    $token = $c->make('apiToken');

    expect($token->value)->toBe('t-42')
        ->and($c->make(ApiToken::class))->toBe($token);
});

it('registers #[Bean] methods declared on a plain #[Component]', function () {
    $c = beanFixtureContainer();

    /** @var Stamp $stamp */
    $stamp = $c->make('inkStamp');

    expect($stamp->ink)->toBe('blue')
        ->and($c->make(Stamp::class))->toBe($stamp);
});
