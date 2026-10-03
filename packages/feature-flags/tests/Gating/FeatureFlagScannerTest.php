<?php

declare(strict_types=1);

use Firefly\Data\Proxy\InterceptorRegistry;
use Firefly\FeatureFlags\Gating\FeatureFlagAdviceSource;
use Firefly\FeatureFlags\Gating\FeatureFlagMethodDescriptor;
use Firefly\FeatureFlags\Gating\FeatureFlagMethodInterceptor;
use Firefly\FeatureFlags\Scanner\FeatureFlagScanner;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\CheckoutService;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\ReportService;
use Firefly\FeatureFlags\Tests\Fixtures\GatedBeans\VirtualFallbackService;
use Firefly\FeatureFlags\Tests\Fixtures\InheritedPlain\ServiceChild;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Container\Container;

/** @return array<string, string> */
function featureFlagsFixtureRoot(string $directory): array
{
    $namespace = 'Firefly\\FeatureFlags\\Tests\\Fixtures\\'.str_replace('/', '\\', $directory).'\\';

    return [$namespace => dirname(__DIR__).'/Fixtures/'.$directory];
}

it('compiles method- and class-level rules and shields a fallback from the class attribute', function (): void {
    $advice = (new FeatureFlagScanner)->scanProxyAdvice(featureFlagsFixtureRoot('GatedBeans'));

    expect(array_keys($advice))->toBe([CheckoutService::class, ReportService::class, VirtualFallbackService::class])
        ->and(array_keys($advice[CheckoutService::class]))->toBe(['checkout', 'optOut', 'price'])
        ->and($advice[CheckoutService::class]['price'])->toBe([
            'class' => CheckoutService::class, 'method' => 'price', 'key' => 'new-pricing', 'variant' => null,
            'default' => false, 'fallback' => 'legacyPrice', 'route' => false,
        ])
        ->and($advice[CheckoutService::class]['optOut']['default'])->toBeTrue()
        ->and(array_keys($advice[ReportService::class]))->toBe(['daily', 'weekly'])
        ->and(array_keys($advice[VirtualFallbackService::class]))->toBe(['run']);
});

it('refuses every placement no proxy could enforce, with a sentence', function (string $directory, string $message): void {
    expect(fn () => (new FeatureFlagScanner)->scan(featureFlagsFixtureRoot('Refused/'.$directory)))
        ->toThrow(ConfigurationException::class, $message);
})->with([
    'final class' => ['FinalService', 'the class is final and a proxy must extend it'],
    'nothing post-processes it' => ['PlainHelper', 'nothing post-processes'],
    'static method' => ['StaticGate', 'a static call has no instance for a proxy to wrap'],
    'fallback is the method' => ['SelfFallback', 'names the gated method itself as its fallback'],
    'missing fallback' => ['MissingFallback', 'does not declare'],
    'invalid key' => ['BadKey', 'is not a valid flag key'],
    'final method' => ['FinalMethod', 'the method is final and a proxy must override it'],
    'magic method' => ['MagicMethod', 'never routed through the advice chain'],
    'private fallback' => ['PrivateFallback', 'not public'],
    'static fallback' => ['StaticFallback', 'static'],
    'fallback arity' => ['ArityFallback', 'requires 1 arguments'],
    'invalid variant' => ['BadVariant', 'contain no comma'],
    'abstract class attribute' => ['AbstractBase', 'class-level #[FeatureFlag] is not inherited'],
]);

it('keeps inherited method gates on a service and drops unenforced inherited rows on plain children', function (): void {
    $advice = (new FeatureFlagScanner)->scanProxyAdvice(featureFlagsFixtureRoot('InheritedPlain'));

    expect(array_keys($advice))->toBe([ServiceChild::class])
        ->and(array_keys($advice[ServiceChild::class]))->toBe(['run']);
});

it('declares fail-closed advice order 80 and renders its row as a literal', function (): void {
    $source = new FeatureFlagAdviceSource;
    $advice = $source->advice();
    $rendered = $source->render((new FeatureFlagMethodDescriptor('A', 'b', 'k'))->toArray());

    expect([$advice->id, $advice->order, $advice->inertWhenUnbound, $advice->interceptorClass, $advice->descriptorClass])
        ->toBe(['featureflag', 80, false, FeatureFlagMethodInterceptor::class, FeatureFlagMethodDescriptor::class])
        ->and($rendered)->toStartWith('\\Firefly\\FeatureFlags\\Gating\\FeatureFlagMethodDescriptor::fromArray(')
        ->and($rendered)->toContain("'key' => 'k'")
        ->and($rendered)->toContain("'route' => false");
    // The rendered literal is loaded for real by CachedBootTest (packages/cli), which boots the cached proxy.
});

it('refuses to bind a compiled gate when its interceptor is absent', function (): void {
    $registry = new InterceptorRegistry(new Container);

    expect(fn () => $registry->for((new FeatureFlagAdviceSource)->advice()))
        ->toThrow(ConfigurationException::class, 'no such bean is bound');
});
