<?php

declare(strict_types=1);

use Firefly\Admin\Format;

/**
 * A qualified name is discriminated by its LEAF and a path by its HEAD, and that is the whole reason these
 * two exist as a pair: the view puts the leaf on the first line and the stem, dim, under it.
 */
it('splits a qualified name into the leaf that identifies it and the stem that locates it', function () {
    expect(Format::leafOf('App\\Http\\Controllers\\OrderController'))->toBe('OrderController')
        ->and(Format::stemOf('App\\Http\\Controllers\\OrderController'))->toBe('App\\Http\\Controllers');
});

it('takes the separator as a parameter, so a dotted config key splits the same way', function () {
    expect(Format::leafOf('firefly.observability.metrics.store', '.'))->toBe('store')
        ->and(Format::stemOf('firefly.observability.metrics.store', '.'))->toBe('firefly.observability.metrics');
});

it('treats an unqualified name as all leaf and no stem', function () {
    expect(Format::leafOf('Closure'))->toBe('Closure')
        ->and(Format::stemOf('Closure'))->toBe('')
        ->and(Format::leafOf(''))->toBe('')
        ->and(Format::stemOf(''))->toBe('');
});

// The two older helpers are called from views this wave does not touch (the bean graph, the entity map),
// so their exact output — namespaceOf keeps its trailing separator — is pinned here as well.
it('leaves shortClass and namespaceOf byte-for-byte what they were', function () {
    expect(Format::shortClass('App\\Http\\OrderController'))->toBe('OrderController')
        ->and(Format::namespaceOf('App\\Http\\OrderController'))->toBe('App\\Http\\')
        ->and(Format::namespaceOf('Closure'))->toBe('');
});
