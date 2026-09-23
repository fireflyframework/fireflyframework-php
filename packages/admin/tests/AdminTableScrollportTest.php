<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTableCapstoneTestCase;

uses(AdminTableCapstoneTestCase::class);

it('gives the table wrapper a height, which is the whole reason a sticky header can stick', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain('--table-vh:68vh')
        ->toContain('.tw{overflow:auto;max-height:var(--table-vh)}');
});

/**
 * THE OPT-OUT HAS TO SELECT SOMETHING, and this is the assertion that says which elements. A rule in the
 * sheet is not a feature; `.tw.free` shipped for a while matching no element in any view, under a test that
 * only looked for its text in the stylesheet and so could never have noticed.
 *
 * The set that takes it is precise: a wrapper around a table with no `<thead>`. The scrollport exists to
 * give `position:sticky` something to stick to, and the overview's four panels draw headerless summary
 * tables under a "All traffic →" footer link. Every other `.tw` on the dashboard draws a header and keeps
 * the scrollport, which is what the second half of this asserts — an opt-out that had leaked onto the
 * listings would have disabled the sticky header this wave exists to fix.
 */
it('puts the opt-out on the headerless panels that take it, and on no listing', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $overview = (string) $this->get('/firefly')->assertStatus(200)->getContent();
    $listing = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($overview)->toContain('.tw.free{max-height:none}')
        ->toContain('<div class="tw free">')
        // Every wrapper the overview draws takes the opt-out; none is left on the plain rule.
        ->not->toContain('<div class="tw">')
        ->and($listing)->toContain('<div class="tw">')
        ->not->toContain('<div class="tw free">');
});

/**
 * The scroll restore is a documented, switchable feature rather than a line of script nobody voted for.
 * Its behaviour — restored on a reload, not on a fresh navigation — is measured in the browser suite
 * (tests/Browser/AdminTablesTest.php); this is the proof that the key reaches the page at all.
 */
it('emits the scroll restore by default', function () {
    /** @var AdminTableCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings')->assertStatus(200)->getContent();

    expect($body)->toContain("'firefly-admin-scroll:'")
        ->toContain("entry.type === 'reload'");
});
