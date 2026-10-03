<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Telemetry\FeatureFlagMetrics;
use Firefly\FeatureFlags\Telemetry\NoOpFeatureFlagMetrics;
use Firefly\FeatureFlags\Tests\Support\FixedProvider;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use Illuminate\Foundation\Application;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\interfaces\flags\Client;
use OpenFeature\OpenFeatureAPI;

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
        [FlagdEvaluator::class => new StaticFlagdEvaluator],
        needs: ['cache'],
    );
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
