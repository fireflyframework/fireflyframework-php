<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Value\ConfigValueResolver;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $items
 */
function resolver(array $items): ConfigValueResolver
{
    return new ConfigValueResolver(new Config(new Repository($items)));
}

afterEach(fn () => putenv('FIREFLY_CVR_TEST'));

it('resolves ${key} from config first', function () {
    $r = resolver(['mail' => ['host' => 'smtp.local']]);

    expect($r->resolve('${mail.host}'))->toBe('smtp.local');
});

it('falls back to env, then to the default', function () {
    $r = resolver([]);
    putenv('FIREFLY_CVR_TEST=from-env');

    expect($r->resolve('${FIREFLY_CVR_TEST}'))->toBe('from-env')
        ->and($r->resolve('${nope.key:fallback}'))->toBe('fallback')
        ->and($r->resolve('${nope.key}'))->toBeNull();
});

it('evaluates #{expr} and returns literals unchanged', function () {
    $r = resolver([]);

    expect($r->resolve('#{3 * 4}'))->toBe(12)
        ->and($r->resolve('plain'))->toBe('plain');
});
