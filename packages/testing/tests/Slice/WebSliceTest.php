<?php

declare(strict_types=1);

use Firefly\Testing\Slice\WebSliceTestCase;

uses(WebSliceTestCase::class);

it('boots a web slice and serves a sliced controller route', function () {
    /** @var WebSliceTestCase $this */
    $this->webSlice(scan: [
        'Firefly\\Testing\\Tests\\Fixtures\\Slice\\' => dirname(__DIR__).'/Fixtures/Slice',
    ]);

    $response = $this->get('/slice/ping');

    expect($response->status())->toBe(200)
        ->and($response->json('pong'))->toBeTrue();
});
