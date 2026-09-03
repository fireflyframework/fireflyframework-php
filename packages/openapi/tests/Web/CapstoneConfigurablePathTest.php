<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\CustomPathCapstoneTestCase;

uses(CustomPathCapstoneTestCase::class);

it('mounts both routes at the configured base path and nowhere else', function () {
    /** @var CustomPathCapstoneTestCase $this */
    $this->get('/docs/api.json')->assertStatus(200);
    $this->get('/docs')->assertStatus(200);

    $this->getJson('/openapi.json')->assertStatus(404);
    $this->getJson('/openapi')->assertStatus(404);
});

it('points the relocated viewer at the relocated spec', function () {
    /** @var CustomPathCapstoneTestCase $this */
    expect($this->responseBody($this->get('/docs')))->toContain('/docs/api.json');
});
