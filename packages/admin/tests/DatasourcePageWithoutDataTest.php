<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

it('renders the datasource page with no data-layer panel when firefly/data is not booted', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/datasource')
        ->assertStatus(200)
        ->assertSee('Datasource', false)
        ->assertDontSee('Data layer', false);
});
