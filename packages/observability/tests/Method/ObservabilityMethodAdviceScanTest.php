<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;
use Firefly\Observability\Tests\Fixtures\Method\ClassLevelService;
use Firefly\Observability\Tests\Fixtures\Method\CountedService;
use Firefly\Observability\Tests\Fixtures\Method\ObservedService;
use Firefly\Observability\Tests\Fixtures\Method\TimedService;

/**
 * The PSR-4 root of the well-formed fixtures, resolved for THIS file: a Pest test file is a plain PHP file,
 * so a helper it shares with a sibling would depend on the order the two are loaded in.
 *
 * @return array<string, string>
 */
function observabilityAdvicePsr4(string $directory = 'Method'): array
{
    return ['Firefly\\Observability\\Tests\\Fixtures\\'.$directory => dirname(__DIR__).'/Fixtures/'.$directory];
}

/*
 | scanProxyAdvice() is what the AdviceSource hands ProxyPlanner, and the planner writes what it is given
 | straight into proxy-plan.php. Both sorts are therefore load-bearing rather than cosmetic: a plan whose rows
 | arrive in filesystem order var_exports differently on two machines with the same source, and a byte-unstable
 | compiled artifact is a cache that never validates. Reflection returns methods in DECLARATION order, so
 | TimedService — which declares place() before importAll() — only comes back keyed importAll-first if the
 | per-method ksort really ran.
 */

it('groups the rows by class and sorts both the classes and each class\'s methods', function (): void {
    $advice = (new ObservabilityMethodScanner)->scanProxyAdvice(observabilityAdvicePsr4());

    expect(array_keys($advice))->toBe([ClassLevelService::class, CountedService::class, ObservedService::class, TimedService::class])
        ->and(array_keys($advice[TimedService::class]))->toBe(['explode', 'importAll', 'place'])
        ->and(array_keys($advice[ClassLevelService::class]))->toBe(['alsoCounted', 'inherited', 'overridden']);
});

it('carries each descriptor through to the plan row unchanged', function (): void {
    $scanner = new ObservabilityMethodScanner;
    $advice = $scanner->scanProxyAdvice(observabilityAdvicePsr4());

    $descriptors = [];
    foreach ($scanner->scan(observabilityAdvicePsr4()) as $rule) {
        $descriptors[$rule->key()] = $rule;
    }

    expect($advice[TimedService::class]['place'])->toBe([
        'class' => TimedService::class,
        'method' => 'place',
        'timed' => ['name' => 'orders.place', 'tags' => ['tier' => 'gold'], 'longTask' => false],
        'counted' => null,
        'observed' => null,
    ])
        ->and($advice[TimedService::class]['place'])->toBe($descriptors[TimedService::class.'::place']->toArray())
        ->and($advice[TimedService::class]['importAll'])->toBe($descriptors[TimedService::class.'::importAll']->toArray())
        ->and($advice[ObservedService::class]['ship'])->toBe($descriptors[ObservedService::class.'::ship']->toArray())
        ->and(ObservabilityMethodDescriptor::fromArray($advice[TimedService::class]['place']))->toEqual($descriptors[TimedService::class.'::place']);
});

/*
 | Every refusal lives in scan(), which scanProxyAdvice() is built on, so the entry point cannot change the
 | answer: firefly:cache reaches the scan through the AdviceSource (scanProxyAdvice) and an uncached dev boot
 | reaches it the same way, and a rule either of them compiled while only the other refused it would reach a
 | proxy that cannot apply it with nothing thrown and nothing logged.
 */

it('refuses, from scanProxyAdvice() too, a metric on a class nothing post-processes', function (): void {
    expect(fn () => (new ObservabilityMethodScanner)->scanProxyAdvice(observabilityAdvicePsr4('Unstereotyped')))
        ->toThrow(ConfigurationException::class, 'carries no #[Component]-family stereotype');
});

it('refuses, from scanProxyAdvice() too, a metric on a final class', function (): void {
    expect(fn () => (new ObservabilityMethodScanner)->scanProxyAdvice(observabilityAdvicePsr4('FinalService')))
        ->toThrow(ConfigurationException::class, 'is final and a proxy must extend it');
});

it('refuses, from scanProxyAdvice() too, a metric on a final method', function (): void {
    expect(fn () => (new ObservabilityMethodScanner)->scanProxyAdvice(observabilityAdvicePsr4('FinalMethod')))
        ->toThrow(ConfigurationException::class, 'is final and a proxy must override it');
});

it('refuses, from scanProxyAdvice() too, #[Timed(percentiles:)]', function (): void {
    expect(fn () => (new ObservabilityMethodScanner)->scanProxyAdvice(observabilityAdvicePsr4('Percentiles')))
        ->toThrow(ConfigurationException::class, 'firefly.observability.metrics.distribution.per-meter');
});

it('refuses, from scanProxyAdvice() too, #[Timed(description:)]', function (): void {
    expect(fn () => (new ObservabilityMethodScanner)->scanProxyAdvice(observabilityAdvicePsr4('Description')))
        ->toThrow(ConfigurationException::class, 'every `# HELP` line from the meter name and its type');
});
