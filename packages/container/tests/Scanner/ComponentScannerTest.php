<?php

declare(strict_types=1);

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scope;
use Firefly\Container\Tests\Fixtures\AppConfig;
use Firefly\Container\Tests\Fixtures\Clock;
use Firefly\Container\Tests\Fixtures\EdgeConfig;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;
use Firefly\Container\Tests\Fixtures\Gadget;
use Firefly\Container\Tests\Fixtures\Greeter;
use Firefly\Container\Tests\Fixtures\LoudGreeter;
use Firefly\Container\Tests\Fixtures\SpanishGreeter;
use Firefly\Container\Tests\Fixtures\Widget;

/**
 * @return list<ComponentDescriptor>
 */
function scanFixtures(): array
{
    return (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);
}

it('discovers every #[Component] under a PSR-4 namespace', function () {
    $classes = array_map(static fn (ComponentDescriptor $d): string => $d->class, scanFixtures());

    expect($classes)->toContain(EnglishGreeter::class)
        ->toContain(SpanishGreeter::class)
        ->toContain(LoudGreeter::class)
        ->toContain(AppConfig::class)
        // Greeter is an interface with no stereotype; Clock has no stereotype:
        ->not->toContain(Greeter::class)
        ->not->toContain(Clock::class);
});

it('captures stereotype, name, primary, order, qualifier, interfaces', function () {
    $byClass = [];
    foreach (scanFixtures() as $d) {
        $byClass[$d->class] = $d;
    }

    $english = $byClass[EnglishGreeter::class];
    expect($english->stereotype)->toBe('service')
        ->and($english->primary)->toBeTrue()
        ->and($english->order)->toBe(10)
        ->and($english->interfaces)->toContain(Greeter::class);

    $spanish = $byClass[SpanishGreeter::class];
    expect($spanish->name)->toBe('spanish')
        ->and($spanish->qualifier)->toBe('spanish')
        ->and($spanish->primary)->toBeFalse();
});

it('captures #[Bean] methods on #[Configuration] classes', function () {
    $config = null;
    foreach (scanFixtures() as $d) {
        if ($d->class === AppConfig::class) {
            $config = $d;
        }
    }

    expect($config)->not->toBeNull();

    if (! $config instanceof ComponentDescriptor) {
        throw new RuntimeException('AppConfig descriptor not found in scan results.');
    }

    expect($config->stereotype)->toBe('configuration')
        ->and($config->beans)->toHaveCount(1)
        ->and($config->beans[0]->method)->toBe('clock')
        ->and($config->beans[0]->returns)->toBe(Clock::class)
        ->and($config->beans[0]->name)->toBe('utcClock')
        ->and($config->beans[0]->scope)->toBe(Scope::Singleton);
});

it('records the empty-string return-type contract for builtin/untyped #[Bean] returns', function () {
    $edge = null;
    foreach (scanFixtures() as $d) {
        if ($d->class === EdgeConfig::class) {
            $edge = $d;
        }
    }

    expect($edge)->not->toBeNull();

    if (! $edge instanceof ComponentDescriptor) {
        throw new RuntimeException('EdgeConfig descriptor not found in scan results.');
    }

    $byMethod = [];
    foreach ($edge->beans as $bean) {
        $byMethod[$bean->method] = $bean;
    }

    expect($byMethod['typedClass']->returns)->toBe(Gadget::class)
        ->and($byMethod['builtinReturn']->returns)->toBe('')
        ->and($byMethod['nullableClass']->returns)->toBe(Widget::class);
});

it('scans cleanly when the directory does not exist', function () {
    $result = (new ComponentScanner)->scan(['Nope\\' => __DIR__.'/does-not-exist']);

    expect($result)->toBe([]);
});
