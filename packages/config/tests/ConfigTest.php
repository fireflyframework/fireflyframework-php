<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $items
 */
function makeConfig(array $items): Config
{
    return new Config(new Repository($items));
}

it('reads typed scalars', function () {
    $c = makeConfig(['app' => ['name' => 'Ember', 'workers' => 4, 'debug' => true, 'tags' => ['a', 'b']]]);

    expect($c->string('app.name'))->toBe('Ember')
        ->and($c->int('app.workers'))->toBe(4)
        ->and($c->bool('app.debug'))->toBeTrue()
        ->and($c->array('app.tags'))->toBe(['a', 'b'])
        ->and($c->has('app.name'))->toBeTrue()
        ->and($c->has('app.missing'))->toBeFalse();
});

it('applies defaults when a key is absent', function () {
    $c = makeConfig([]);

    expect($c->string('app.name', 'fallback'))->toBe('fallback')
        ->and($c->int('app.workers', 1))->toBe(1)
        ->and($c->bool('app.debug', false))->toBeFalse();
});

it('throws on a missing required key', function () {
    makeConfig([])->string('app.name');
})->throws(ConfigurationException::class);

it('throws on a type mismatch', function () {
    makeConfig(['app' => ['workers' => 'not-an-int']])->int('app.workers');
})->throws(ConfigurationException::class);

it('coerces common boolean spellings', function () {
    $c = makeConfig(['flags' => ['on' => '1', 'off' => 'false']]);

    expect($c->bool('flags.on'))->toBeTrue()
        ->and($c->bool('flags.off'))->toBeFalse();
});

it('uses the default when a key is present but explicitly null', function () {
    expect(makeConfig(['mail' => ['port' => null]])->int('mail.port', 587))->toBe(587)
        ->and(makeConfig(['mail' => ['host' => null]])->string('mail.host', 'localhost'))->toBe('localhost');
});

it('throws when a present-but-null required key has no default', function () {
    makeConfig(['mail' => ['host' => null]])->string('mail.host');
})->throws(ConfigurationException::class);
