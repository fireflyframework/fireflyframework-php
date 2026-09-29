<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableScrollportRefusedTestCase;

uses(AdminTableScrollportRefusedTestCase::class);

// The value is interpolated into a <style> element. There is no escaping that makes an arbitrary string
// safe there, so it is refused at the settings boundary and this is the end-to-end proof.
it('refuses a max-height that is not a length, rather than writing it into the stylesheet', function () {
    /** @var AdminTableScrollportRefusedTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain('--table-vh:68vh')
        ->not->toContain('body{display:none')
        ->not->toContain(AdminTableScrollportRefusedTestCase::HOSTILE_HEIGHT);
});
