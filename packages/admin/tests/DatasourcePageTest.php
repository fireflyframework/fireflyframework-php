<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DatasourceCapstoneTestCase;

uses(DatasourceCapstoneTestCase::class);

it('shows that exception translation is on and what the default transaction timeout is', function () {
    /** @var DatasourceCapstoneTestCase $this */
    $this->get('/firefly/datasource')
        ->assertStatus(200)
        ->assertSee('Data layer', false)
        ->assertSee('Exception translation', false)
        ->assertSee('firefly.data.exception-translation.enabled', false)
        ->assertSee('30s', false);
});
