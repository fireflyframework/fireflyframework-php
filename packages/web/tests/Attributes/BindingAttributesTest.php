<?php

declare(strict_types=1);

use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestHeader;
use Firefly\Web\Attributes\UploadedFile;

it('models each binding attribute', function () {
    expect((new PathVariable('id'))->name)->toBe('id')
        ->and((new PathVariable)->name)->toBeNull()
        ->and((new QueryParam('page', 1, true))->name)->toBe('page')
        ->and((new QueryParam('page', 1, true))->default)->toBe(1)
        ->and((new QueryParam('page', 1, true))->required)->toBeTrue()
        ->and((new QueryParam)->required)->toBeFalse()
        ->and(new RequestBody)->toBeInstanceOf(RequestBody::class)
        ->and((new RequestHeader('X-Api-Key', 'none'))->name)->toBe('X-Api-Key')
        ->and((new RequestHeader('X-Api-Key', 'none'))->default)->toBe('none')
        ->and((new UploadedFile('avatar'))->name)->toBe('avatar');
});
