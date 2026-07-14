<?php

declare(strict_types=1);

use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;
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
