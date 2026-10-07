<?php

declare(strict_types=1);

use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Boot\FeatureFlagsLifecycle;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Event\FeatureFlagsChanged;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Telemetry\FeatureFlagMetrics;
use Firefly\FeatureFlags\Telemetry\NoOpFeatureFlagMetrics;
use Firefly\FeatureFlags\Tests\Support\FixedProvider;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use Firefly\FeatureFlags\Tests\Support\StubFlagSource;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container as IlluminateContainer;
use Illuminate\Foundation\Application;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\interfaces\flags\Client;
use OpenFeature\interfaces\provider\Provider;
use OpenFeature\OpenFeatureAPI;
use Psr\Log\NullLogger;

beforeEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));
afterEach(fn () => OpenFeatureAPI::getInstance()->setProvider(new NoOpProvider));

/**
 * @param  array<string, mixed>  $featureFlags
 * @param  array<string, string>  $scan
 */
function featureFlagsApp(array $featureFlags, array $scan = []): Application
{
    return fireflyApplication(
        ['firefly' => ['feature-flags' => ['enabled' => true, ...$featureFlags], 'scan' => ['paths' => $scan]]],
        [FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );
}

function featureFlagsLifecycleFor(Provider $provider, ?FlagRegistry $registry = null): FeatureFlagsLifecycle
{
    $illuminate = new IlluminateContainer;
    $illuminate->instance(Provider::class, $provider);
    if ($registry !== null) {
        $illuminate->instance(FlagRegistry::class, $registry);
    }

    return new FeatureFlagsLifecycle(new FireflyContainer($illuminate, new ComponentManifest([])));
}

it('wires the registry, provider, and exact facade client from shipped manifests', function (): void {
    $app = featureFlagsApp(['flags' => ['kill-switch' => true, 'checkout-flow' => 'v2']]);
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);
    /** @var FeatureFlags $flags */
    $flags = $context->get(FeatureFlags::class);

    expect($flags->isEnabled('kill-switch'))->toBeTrue()
        ->and($flags->getString('checkout-flow', 'v1'))->toBe('v2')
        ->and($context->get(FlagRegistry::class))->toBeInstanceOf(FlagRegistry::class)
        ->and($context->get(FeatureFlagMetrics::class))->toBeInstanceOf(NoOpFeatureFlagMetrics::class)
        ->and($context->get(Client::class))->toBe($flags->client())
        ->and(OpenFeatureAPI::getInstance()->getProvider())->toBe($flags->provider());

    $context->close();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBeInstanceOf(NoOpProvider::class);
});

it('restores the provider it replaced only while it still owns the global slot', function (): void {
    $prior = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($prior);
    $first = featureFlagsApp(['flags' => ['a' => true]]);
    /** @var ApplicationContext $firstContext */
    $firstContext = $first->make(ApplicationContext::class);
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBeInstanceOf(FireflyFlagProvider::class);
    $firstContext->close();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($prior);

    $second = featureFlagsApp(['flags' => ['a' => true]]);
    $other = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($other);
    /** @var ApplicationContext $secondContext */
    $secondContext = $second->make(ApplicationContext::class);
    $secondContext->close();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($other);
});

it('does not restore a stopped application through a later overlapping boot', function (): void {
    $prior = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($prior);
    $first = featureFlagsApp(['flags' => ['a' => true]])->make(ApplicationContext::class);
    $second = featureFlagsApp(['flags' => ['b' => true]])->make(ApplicationContext::class);
    $secondProvider = OpenFeatureAPI::getInstance()->getProvider();

    $first->close();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($secondProvider);
    $second->close();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($prior);
});

it('keeps a shared external provider installed until the last overlapping lifecycle stops', function (bool $firstStopsFirst): void {
    $prior = new NoOpProvider;
    $shared = new FixedProvider;
    $illuminate = new IlluminateContainer;
    $illuminate->instance(Provider::class, $shared);
    $beans = new FireflyContainer($illuminate, new ComponentManifest([]));
    $first = new FeatureFlagsLifecycle($beans);
    $second = new FeatureFlagsLifecycle($beans);
    OpenFeatureAPI::getInstance()->setProvider($prior);

    $first->start();
    $second->start();
    ($firstStopsFirst ? $first : $second)->stop();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($shared);
    ($firstStopsFirst ? $second : $first)->stop();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($prior);
})->with([true, false]);

it('retains the middle owner when three lifecycles share a provider', function (array $stopOrder): void {
    $prior = new NoOpProvider;
    $shared = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($prior);
    $owners = [featureFlagsLifecycleFor($shared), featureFlagsLifecycleFor($shared), featureFlagsLifecycleFor($shared)];
    foreach ($owners as $owner) {
        $owner->start();
    }

    foreach ($stopOrder as $index => $ownerIndex) {
        if (! is_int($ownerIndex)) {
            throw new UnexpectedValueException('A lifecycle stop index must be an integer.');
        }
        $owners[$ownerIndex]->stop();
        expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($index === 2 ? $prior : $shared);
    }
})->with([[[0, 1, 2]], [[0, 2, 1]], [[1, 0, 2]], [[1, 2, 0]], [[2, 0, 1]], [[2, 1, 0]]]);

it('restores the middle distinct provider after a shared outer owner stops', function (): void {
    $prior = new NoOpProvider;
    $shared = new FixedProvider;
    $middleProvider = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($prior);
    $first = featureFlagsLifecycleFor($shared);
    $middle = featureFlagsLifecycleFor($middleProvider);
    $last = featureFlagsLifecycleFor($shared);

    $first->start();
    $middle->start();
    $last->start();
    $first->stop();
    $last->stop();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($middleProvider);
    $middle->stop();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($prior);
});

it('restores the exact prior provider after a startup listener evaluates through the facade', function (): void {
    $events = new class implements ApplicationEventPublisher
    {
        public ?FeatureFlags $flags = null;

        public bool $evaluated = false;

        public function publish(object $event): void
        {
            if ($event instanceof FeatureFlagsChanged) {
                $this->evaluated = $this->flags?->isEnabled('a') ?? false;
            }
        }
    };
    $logger = new NullLogger;
    $registry = new FlagRegistry([new StubFlagSource('config', 100, 0.0, ['a' => true])], new CacheBook(new Repository(new ArrayStore), $logger), $events, $logger);
    $provider = new FireflyFlagProvider($registry, new StaticFlagdEvaluator);
    $events->flags = new FeatureFlags($provider, new EvaluationContextResolver);
    $lifecycle = featureFlagsLifecycleFor($provider, $registry);
    $prior = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($prior);

    $lifecycle->start();
    expect($events->evaluated)->toBeTrue()
        ->and(OpenFeatureAPI::getInstance()->getProvider())->toBe($provider);
    $lifecycle->stop();
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($prior);
});

it('restores the prior provider if a failing startup source installed the facade provider first', function (): void {
    $source = new StubFlagSource('file', 200, 0.0, failsStartup: true);
    $source->failure = new RuntimeException('source unavailable');
    $events = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void {}
    };
    $logger = new NullLogger;
    $registry = new FlagRegistry([$source], new CacheBook(new Repository(new ArrayStore), $logger), $events, $logger);
    $provider = new FireflyFlagProvider($registry, new StaticFlagdEvaluator);
    $flags = new FeatureFlags($provider, new EvaluationContextResolver);
    $source->beforeLoad = static function () use ($flags): void {
        $flags->client();
    };
    $lifecycle = featureFlagsLifecycleFor($provider, $registry);
    $prior = new FixedProvider;
    OpenFeatureAPI::getInstance()->setProvider($prior);

    expect(fn () => $lifecycle->start())->toThrow(RuntimeException::class, 'source unavailable');
    expect(OpenFeatureAPI::getInstance()->getProvider())->toBe($prior);
});

it('attaches hooks only to the firefly client without accumulating on reboot', function (): void {
    featureFlagsApp(['flags' => ['a' => true]]);
    $context = featureFlagsApp(['flags' => ['a' => true]])->make(ApplicationContext::class);
    /** @var FeatureFlags $flags */
    $flags = $context->get(FeatureFlags::class);

    expect(OpenFeatureAPI::getInstance()->getHooks())->toBe([])
        ->and($flags->client()->getHooks())->toHaveCount(1);
});

it('adds the exposure hook only when events.evaluations is enabled', function (): void {
    /** @var FeatureFlags $flags */
    $flags = featureFlagsApp(['flags' => ['a' => true], 'events' => ['evaluations' => true]])->make(ApplicationContext::class)->get(FeatureFlags::class);

    expect($flags->client()->getHooks())->toHaveCount(2);
});

it('refuses invalid inline definitions at boot with the key and reason', function (): void {
    expect(fn () => featureFlagsApp(['flags' => ['bad key' => true]]))
        ->toThrow(InvalidFlagDefinition::class, 'Invalid feature flag [bad key] from source [config]: invalid flag key.');
});

it('backs off to an application provider and leaves the registry unbound', function (): void {
    $app = featureFlagsApp(['flags' => ['ignored' => false]], [
        'Firefly\\FeatureFlags\\Tests\\Fixtures\\External\\' => __DIR__.'/Fixtures/External',
    ]);
    /** @var FeatureFlags $flags */
    $flags = $app->make(ApplicationContext::class)->get(FeatureFlags::class);

    expect($flags->isEnabled('anything'))->toBeTrue()
        ->and($flags->provider())->toBeInstanceOf(FixedProvider::class)
        ->and($flags->details('ignored', false)->metadata)->toBe([])
        ->and($app->bound(FlagRegistry::class))->toBeFalse()
        ->and(OpenFeatureAPI::getInstance()->getProvider())->toBe($flags->provider());
});
