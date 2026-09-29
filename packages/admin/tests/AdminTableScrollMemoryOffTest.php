<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableScrollMemoryOffTestCase;

uses(AdminTableScrollMemoryOffTestCase::class);

it('writes nothing to session storage when the deployment declines the scroll restore', function () {
    /** @var AdminTableScrollMemoryOffTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    // The page still renders its tables and its scrollport; only the memory is gone.
    expect($body)->toContain('.tw{overflow:auto;max-height:var(--table-vh)}')
        ->not->toContain('firefly-admin-scroll')
        ->not->toContain('sessionStorage');
});
