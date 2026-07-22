<?php

declare(strict_types=1);

use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Sort;

it('computes the zero-based offset from the 1-based page and size', function () {
    expect(Pageable::of(1, 20)->offset())->toBe(0)
        ->and(Pageable::of(2, 20)->offset())->toBe(20)
        ->and(Pageable::of(3, 15)->offset())->toBe(30);
});

it('navigates to the next and previous page keeping size + sort', function () {
    $sort = Sort::by('name');
    $pageable = Pageable::of(2, 10, $sort);

    expect($pageable->next()->page)->toBe(3)
        ->and($pageable->next()->size)->toBe(10)
        ->and($pageable->next()->sort)->toBe($sort)
        ->and($pageable->previous()->page)->toBe(1)
        ->and(Pageable::of(1, 10)->previous()->page)->toBe(1); // clamps at 1
});

it('rejects a non-positive page or size', function () {
    expect(fn () => Pageable::of(0, 20))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Pageable::of(1, 0))->toThrow(InvalidArgumentException::class);
});

it('models an unpaged request', function () {
    expect(Pageable::unpaged()->isPaged())->toBeFalse()
        ->and(Pageable::of(1, 20)->isPaged())->toBeTrue()
        ->and(Pageable::unpaged()->offset())->toBe(0);
});
