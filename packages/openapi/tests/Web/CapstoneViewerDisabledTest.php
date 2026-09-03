<?php

declare(strict_types=1);

use Firefly\OpenApi\Tests\Support\ViewerDisabledCapstoneTestCase;

uses(ViewerDisabledCapstoneTestCase::class);

it('serves the document with the viewer switched off independently', function () {
    /** @var ViewerDisabledCapstoneTestCase $this */
    $this->get('/openapi.json')->assertStatus(200);
    $this->getJson('/openapi')->assertStatus(404);
});
