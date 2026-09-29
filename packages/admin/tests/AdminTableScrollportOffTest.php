<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableScrollportOffTestCase;

uses(AdminTableScrollportOffTestCase::class);

it('lets a deployment turn the scrollport off through configuration', function () {
    /** @var AdminTableScrollportOffTestCase $this */
    expect((string) $this->get('/firefly/mappings')->assertStatus(200)->getContent())
        ->toContain('--table-vh:none');
});
