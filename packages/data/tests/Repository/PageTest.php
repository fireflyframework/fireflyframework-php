<?php

declare(strict_types=1);

use Firefly\Data\Repository\Page;

it('computes total pages with a ceiling', function () {
    expect((new Page([], 0, 1, 20))->totalPages())->toBe(0)
        ->and((new Page([], 20, 1, 20))->totalPages())->toBe(1)
        ->and((new Page([], 21, 1, 20))->totalPages())->toBe(2)
        ->and((new Page([], 41, 1, 20))->totalPages())->toBe(3);
});

it('knows whether there is a next / previous page', function () {
    $middle = new Page([1, 2], 41, 2, 20);

    expect($middle->hasNext())->toBeTrue()
        ->and($middle->hasPrevious())->toBeTrue();

    $first = new Page([1, 2], 41, 1, 20);
    expect($first->hasPrevious())->toBeFalse()->and($first->hasNext())->toBeTrue();

    $last = new Page([1], 41, 3, 20);
    expect($last->hasNext())->toBeFalse()->and($last->hasPrevious())->toBeTrue();
});

it('reports the number of elements on the page', function () {
    expect((new Page(['a', 'b'], 41, 1, 20))->numberOfElements())->toBe(2);
});

it('maps items while preserving paging metadata', function () {
    $mapped = (new Page([1, 2, 3], 3, 1, 20))->map(static fn (int $n): int => $n * 10);

    expect($mapped->items)->toBe([10, 20, 30])
        ->and($mapped->total)->toBe(3)
        ->and($mapped->page)->toBe(1)
        ->and($mapped->size)->toBe(20);
});
