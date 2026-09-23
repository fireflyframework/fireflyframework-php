<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

it('gives the table wrapper a height, which is the whole reason a sticky header can stick', function () {
    /** @var AdminCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain('--table-vh:68vh')
        ->toContain('.tw{overflow:auto;max-height:var(--table-vh)}');
});

// The opt-out, because a scrollport is wrong for a panel whose rows are a fixed handful: a four-row health
// table inside a box with 68vh of height reserved for it is mostly empty box.
it('offers a panel the way out of the scrollport it does not want', function () {
    /** @var AdminCapstoneTestCase $this */
    expect((string) $this->get('/firefly/mappings')->getContent())
        ->toContain('.tw.free{max-height:none}');
});
