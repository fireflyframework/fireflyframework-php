<?php

declare(strict_types=1);

namespace Lumen\Tests;

uses(LumenTestCase::class);

it('boots the Firefly stack for the lumen sample', function () {
    /** @var LumenTestCase $this */
    $context = $this->fireflyContext();

    expect($context)->not->toBeNull();
})->group('lumen');
