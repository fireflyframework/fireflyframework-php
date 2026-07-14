<?php

declare(strict_types=1);

use Firefly\Container\Container;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;
use Firefly\Container\Tests\Fixtures\Greeter;
use Firefly\Container\Tests\Fixtures\GreetingProbe;
use Firefly\Container\Tests\Fixtures\LoudGreeter;
use Firefly\Container\Tests\Fixtures\SpanishGreeter;
use Illuminate\Container\Container as IlluminateContainer;

function fireflyContainer(): Container
{
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/Fixtures',
    ]);
    $manifest = new ComponentManifest($components);
    $illuminate = new IlluminateContainer;
    (new ContainerRegistrar($illuminate))->register($manifest);

    return new Container($illuminate, $manifest);
}

it('resolves by type, by interface (primary), and by name', function () {
    $c = fireflyContainer();
    expect($c->get(EnglishGreeter::class))->toBeInstanceOf(EnglishGreeter::class)
        ->and($c->get(Greeter::class))->toBeInstanceOf(EnglishGreeter::class) // primary
        ->and($c->getByName('spanish'))->toBeInstanceOf(SpanishGreeter::class)
        ->and($c->has(Greeter::class))->toBeTrue()
        ->and($c->has('nope'))->toBeFalse();
});

it('resolves all implementations sorted by #[Order] (lower first)', function () {
    $c = fireflyContainer();
    $all = $c->getAll(Greeter::class);
    $classes = array_map(static fn ($o) => $o::class, $all);

    // Orders: Loud=5, English=10, Spanish=20.
    expect($classes)->toBe([LoudGreeter::class, EnglishGreeter::class, SpanishGreeter::class]);
});

it('injects a #[Value] constructor parameter via the container', function () {
    putenv('FIREFLY_GREETING=Salut');
    $illuminate = new IlluminateContainer;
    (new ContainerRegistrar($illuminate))->register(new ComponentManifest([]));

    expect($illuminate->make(GreetingProbe::class)->greeting)->toBe('Salut');

    putenv('FIREFLY_GREETING');
});
