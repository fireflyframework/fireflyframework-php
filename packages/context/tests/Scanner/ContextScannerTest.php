<?php

declare(strict_types=1);

use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProfile;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextScanner;
use Firefly\Context\Tests\Fixtures\AbstractLifecycleFixture;
use Firefly\Context\Tests\Fixtures\ConditionalConfigFixture;
use Firefly\Context\Tests\Fixtures\ExplicitListenerWidget;
use Firefly\Context\Tests\Fixtures\InferredListenerWidget;
use Firefly\Context\Tests\Fixtures\LifecycleFixtureWidget;
use Firefly\Context\Tests\Fixtures\ListenerEvent;
use Firefly\Context\Tests\Fixtures\PlainContextFixture;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * @return list<ContextDescriptor>
 */
function scanContextFixtures(): array
{
    return (new ContextScanner)->scan([
        'Firefly\\Context\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);
}

/**
 * @return array<string, ContextDescriptor>
 */
function scanContextFixturesByClass(): array
{
    $byClass = [];
    foreach (scanContextFixtures() as $d) {
        $byClass[$d->class] = $d;
    }

    return $byClass;
}

it('captures #[PostConstruct]/#[PreDestroy] method names', function () {
    $descriptor = scanContextFixturesByClass()[LifecycleFixtureWidget::class];

    expect($descriptor->postConstruct)->toBe(['init'])
        ->and($descriptor->preDestroy)->toBe(['shutdown']);
});

it('captures an explicit #[AsEventListener] event/order verbatim', function () {
    $descriptor = scanContextFixturesByClass()[ExplicitListenerWidget::class];

    expect($descriptor->listeners)->toBe([
        ['method' => 'onEvent', 'event' => ListenerEvent::class, 'order' => 5],
    ]);
});

it('infers a null #[AsEventListener] event from the listener method\'s first parameter type, at SCAN time', function () {
    $descriptor = scanContextFixturesByClass()[InferredListenerWidget::class];

    expect($descriptor->listeners)->toBe([
        ['method' => 'onEvent', 'event' => ListenerEvent::class, 'order' => 0],
    ]);
});

it('captures #[ConditionalOn*] on the class itself as a plain type/args array', function () {
    $descriptor = scanContextFixturesByClass()[ConditionalConfigFixture::class];

    expect($descriptor->conditions)->toBe([
        ['type' => ConditionalOnProperty::class, 'args' => ['feature.enabled', null, true]],
    ]);
});

it('captures #[ConditionalOn*] per #[Bean] method, keyed by method name', function () {
    $descriptor = scanContextFixturesByClass()[ConditionalConfigFixture::class];

    $byMethod = [];
    foreach ($descriptor->beanConditions as $entry) {
        $byMethod[$entry['method']] = $entry['conditions'];
    }

    expect($byMethod['classGated'])->toBe([
        ['type' => ConditionalOnClass::class, 'args' => [ConditionalConfigFixture::class]],
    ])
        ->and($byMethod['profileGated'])->toBe([
            ['type' => ConditionalOnProfile::class, 'args' => ['dev', 'test']],
        ]);
});

it('reconstructs real condition attribute instances via conditionInstances()/beanConditionInstances(), including a VARIADIC constructor', function () {
    $descriptor = scanContextFixturesByClass()[ConditionalConfigFixture::class];

    $classConditions = $descriptor->conditionInstances();
    expect($classConditions)->toHaveCount(1);

    $classCondition = $classConditions[0];
    if (! $classCondition instanceof ConditionalOnProperty) {
        throw new RuntimeException('Expected a ConditionalOnProperty instance.');
    }

    expect($classCondition->name)->toBe('feature.enabled')
        ->and($classCondition->matchIfMissing)->toBeTrue();

    $profileConditions = $descriptor->beanConditionInstances('profileGated');
    expect($profileConditions)->toHaveCount(1);

    $profileCondition = $profileConditions[0];
    if (! $profileCondition instanceof ConditionalOnProfile) {
        throw new RuntimeException('Expected a ConditionalOnProfile instance.');
    }

    expect($profileCondition->profiles)->toBe(['dev', 'test']);
});

it('contributes NO descriptor for a class with none of the discoverable attributes', function () {
    expect(scanContextFixturesByClass())->not->toHaveKey(PlainContextFixture::class);
});

it('guards abstract classes exactly as ConfigPropertiesScanner does — never scanned, even carrying #[PostConstruct]', function () {
    expect(scanContextFixturesByClass())->not->toHaveKey(AbstractLifecycleFixture::class);
});

it('scans cleanly when the directory does not exist', function () {
    $result = (new ContextScanner)->scan(['Nope\\' => __DIR__.'/does-not-exist']);

    expect($result)->toBe([]);
});

it('throws at SCAN time (never at boot) when #[AsEventListener] has no explicit event and an uninferable first parameter', function () {
    (new ContextScanner)->scan([
        'Firefly\\Context\\Tests\\BadListenerFixtures\\' => __DIR__.'/../BadListenerFixtures',
    ]);
})->throws(ConfigurationException::class);

it('throws ConfigurationException, naming the class and method, when #[AsEventListener] has no explicit event and NO parameters at all', function () {
    (new ContextScanner)->scan([
        'Firefly\\Context\\Tests\\BadListenerNoParamsFixtures\\' => __DIR__.'/../BadListenerNoParamsFixtures',
    ]);
})->throws(
    ConfigurationException::class,
    'Firefly\Context\Tests\BadListenerNoParamsFixtures\NoParamsListenerWidget::onSomething() has no explicit event and no parameters to infer one from.',
);
