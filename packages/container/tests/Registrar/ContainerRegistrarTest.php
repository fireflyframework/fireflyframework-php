<?php

declare(strict_types=1);

use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Tests\Fixtures\Beeper;
use Firefly\Container\Tests\Fixtures\Clock;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;
use Firefly\Container\Tests\Fixtures\FireSiren;
use Firefly\Container\Tests\Fixtures\Greeter;
use Firefly\Container\Tests\Fixtures\LoudGreeter;
use Firefly\Container\Tests\Fixtures\PoliceSiren;
use Firefly\Container\Tests\Fixtures\Siren;
use Firefly\Container\Tests\Fixtures\SoloBeeper;
use Firefly\Container\Tests\Fixtures\SpanishGreeter;
use Illuminate\Container\Container as IlluminateContainer;

function registeredContainer(): IlluminateContainer
{
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);
    $illuminate = new IlluminateContainer;
    (new ContainerRegistrar($illuminate))->register(new ComponentManifest($components));

    return $illuminate;
}

it('binds each component by its class and resolves it', function () {
    $c = registeredContainer();
    expect($c->make(EnglishGreeter::class))->toBeInstanceOf(EnglishGreeter::class);
});

it('makes a singleton return the same instance and a transient a fresh one', function () {
    $c = registeredContainer();
    // EnglishGreeter is default (singleton); resolve twice → identical.
    expect($c->make(EnglishGreeter::class))->toBe($c->make(EnglishGreeter::class));
});

it('registers a named alias from the stereotype name', function () {
    $c = registeredContainer();
    // SpanishGreeter is #[Service('spanish')]; 'spanish' is a plain alias
    // string, so PHPStan/Larastan cannot infer make()'s return type from it.
    /** @var SpanishGreeter $resolved */
    $resolved = $c->make('spanish');
    expect($resolved)->toBeInstanceOf(SpanishGreeter::class);
});

it('binds an interface to its #[Primary] implementation', function () {
    $c = registeredContainer();
    // EnglishGreeter is #[Primary] among the Greeter implementations.
    expect($c->make(Greeter::class))->toBeInstanceOf(EnglishGreeter::class);
});

it('tags every implementation of an interface for list resolution', function () {
    $c = registeredContainer();
    $registrar = new ContainerRegistrar($c);
    $tag = $registrar->tagFor(Greeter::class);

    /** @var list<Greeter> $tagged */
    $tagged = iterator_to_array($c->tagged($tag));
    $classes = array_map(static fn (Greeter $o): string => $o::class, $tagged);

    expect($classes)->toContain(EnglishGreeter::class)
        ->toContain(SpanishGreeter::class)
        ->toContain(LoudGreeter::class);
});

it('registers #[Bean] methods under their return type and name', function () {
    $c = registeredContainer();

    /** @var Clock $clock */
    $clock = $c->make(Clock::class);
    /** @var Clock $namedClock */
    $namedClock = $c->make('utcClock');

    expect($clock)->toBeInstanceOf(Clock::class)
        ->and($clock->zone)->toBe('UTC')
        ->and($namedClock)->toBeInstanceOf(Clock::class);
});

it('binds an interface to its sole implementation when none is #[Primary]', function () {
    $c = registeredContainer();
    // SoloBeeper is the only Beeper implementation and carries no #[Primary];
    // wireInterfaces() falls back to "exactly one implementation total".
    expect($c->make(Beeper::class))->toBeInstanceOf(SoloBeeper::class);
});

it('leaves an interface unbound when multiple implementations have no #[Primary]', function () {
    $c = registeredContainer();
    // PoliceSiren and FireSiren both implement Siren; neither is #[Primary],
    // so the binding is intentionally ambiguous and left unbound.
    expect($c->bound(Siren::class))->toBeFalse();

    $registrar = new ContainerRegistrar($c);
    $tag = $registrar->tagFor(Siren::class);

    /** @var list<Siren> $tagged */
    $tagged = iterator_to_array($c->tagged($tag));
    $classes = array_map(static fn (Siren $o): string => $o::class, $tagged);

    expect($classes)->toContain(PoliceSiren::class)
        ->toContain(FireSiren::class);
});

it('skips beans with an empty return type without binding an empty abstract', function () {
    $c = registeredContainer();
    // EdgeConfig::builtinReturn(): string has a builtin return type, so the
    // scanner records returns === '' and registerBeans() skips it entirely.
    expect($c->bound(''))->toBeFalse();
});
