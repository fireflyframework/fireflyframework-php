<?php

declare(strict_types=1);

use Firefly\Data\Repository\Slice;

it('knows whether there is a next page without ever counting', function () {
    $slice = new Slice(['a', 'b'], true, 2, 2);

    expect($slice->hasNext())->toBeTrue()
        ->and($slice->hasPrevious())->toBeTrue()
        ->and($slice->numberOfElements())->toBe(2)
        ->and($slice->nextPageable()->page)->toBe(3)
        ->and($slice->nextPageable()->size)->toBe(2)
        ->and((new Slice([], false))->hasPrevious())->toBeFalse()
        ->and((new Slice(['x'], false))->map(strtoupper(...))->items)->toBe(['X']);
});
