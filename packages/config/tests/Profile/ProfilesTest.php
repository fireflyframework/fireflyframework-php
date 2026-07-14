<?php

declare(strict_types=1);

use Firefly\Config\Profile\Profiles;

it('reports active membership and lists all', function () {
    $p = new Profiles(['prod', 'eu']);

    expect($p->isActive('prod'))->toBeTrue()
        ->and($p->isActive('eu'))->toBeTrue()
        ->and($p->isActive('dev'))->toBeFalse()
        ->and($p->all())->toBe(['prod', 'eu'])
        ->and($p->isEmpty())->toBeFalse();
});

it('treats an empty set as empty', function () {
    expect((new Profiles([]))->isEmpty())->toBeTrue()
        ->and((new Profiles([]))->isActive('anything'))->toBeFalse();
});
