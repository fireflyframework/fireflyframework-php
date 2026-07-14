<?php

declare(strict_types=1);

use Firefly\Container\Container;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scanner\ComponentScanner;
use Firefly\Container\Scanner\ManifestCompiler;
use Firefly\Container\Tests\Fixtures\Clock;
use Firefly\Container\Tests\Fixtures\EnglishGreeter;
use Firefly\Container\Tests\Fixtures\Greeter;
use Firefly\Container\Tests\Fixtures\LoudGreeter;
use Firefly\Container\Tests\Fixtures\SpanishGreeter;
use Illuminate\Container\Container as IlluminateContainer;

it('boots a container from a cached manifest and resolves the full graph', function () {
    // 1. Scan → compile → write (build time).
    $components = (new ComponentScanner)->scan([
        'Firefly\\Container\\Tests\\Fixtures\\' => __DIR__.'/Fixtures',
    ]);
    $path = sys_get_temp_dir().'/firefly-int-'.bin2hex(random_bytes(6)).'.php';
    (new ManifestCompiler)->write($components, $path);
    expect(file_exists($path))->toBeTrue();

    // 2. Load the frozen manifest (runtime; no reflection) → register → facade.
    $manifest = ComponentManifest::load($path);
    $illuminate = new IlluminateContainer;
    (new ContainerRegistrar($illuminate))->register($manifest);
    $firefly = new Container($illuminate, $manifest);

    // 3. Resolve by type, interface (primary), name, ordered list, and #[Bean].
    // Container::get() returns the generic `object` type; the #[Bean] factory
    // is only known by PHPStan to return Clock via its return-typed method, so
    // narrow the resolved instance explicitly to reach ->zone.
    /** @var Clock $clock */
    $clock = $firefly->get(Clock::class);

    expect($firefly->get(EnglishGreeter::class))->toBeInstanceOf(EnglishGreeter::class)
        ->and($firefly->get(Greeter::class))->toBeInstanceOf(EnglishGreeter::class)
        ->and($firefly->getByName('spanish'))->toBeInstanceOf(SpanishGreeter::class)
        ->and($clock->zone)->toBe('UTC')
        ->and(array_map(static fn (object $o): string => $o::class, $firefly->getAll(Greeter::class)))
        ->toBe([LoudGreeter::class, EnglishGreeter::class, SpanishGreeter::class]);

    unlink($path);
});
