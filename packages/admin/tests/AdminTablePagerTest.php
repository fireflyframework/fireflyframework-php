<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminTablePagedCapstoneTestCase;
use Illuminate\Support\Str;

uses(AdminTablePagedCapstoneTestCase::class);

/**
 * The pager's PAGED branch, which the seven-row listing fixture can never reach.
 *
 * `_pager.blade.php` draws its window, its Previous/Next, its first/last jumps and its `…` gaps only when
 * `ListingPage::isPaged()` is true, and seven rows fit on every size the rows-per-page control offers by
 * default — so a suite that never shrinks the page asserts against a pager with no page controls in it at
 * all. That is the third of the partial that carries listing state across a boundary, which is the bug this
 * wave exists to remove, so it is asserted here against a fixture of twenty-one rows at two per page.
 */
it('carries the search across a page boundary, on every link the pager draws', function () {
    /** @var AdminTablePagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings?q=orders&size=2&page=2')->assertStatus(200)->getContent();

    expect($body)->toContain('page 2 of 3')
        // The page that was asked for is the page that is marked, and its own link is a link to itself.
        ->toContain('<a class="act on" aria-label="Page 2" aria-current="page" href="/firefly/mappings?q=orders&amp;size=2&amp;page=2">2</a>')
        // EVERY jump keeps `q`. This is the assertion the whole partial exists for: the views this replaced
        // concatenated the search into each href by hand, and a link that forgot it widened the listing back
        // to all twenty-one rows — which reads as rows appearing from nowhere, and fails nothing.
        ->toContain('<a class="act" aria-label="Page 3" aria-current="false" href="/firefly/mappings?q=orders&amp;size=2&amp;page=3">3</a>')
        ->toContain('<a class="act" aria-label="Page 1" aria-current="false" href="/firefly/mappings?q=orders&amp;size=2">1</a>')
        ->toMatch('~<a class="act"\s+href="/firefly/mappings\?q=orders&amp;size=2&amp;page=3"\s*>Next</a>~')
        ->toMatch('~<a class="act"\s+href="/firefly/mappings\?q=orders&amp;size=2"\s*>Previous</a>~')
        // And the rows under it are still the narrowed ones on page 2, not merely on page 1.
        ->toContain('OrderController')
        ->not->toContain('WidgetController');
});

// `page=1` and `page=11` are the two positions where a control has nowhere to go, and an anchor that keeps
// its href there is a link to the page the reader is already on.
it('marks the ends of the listing, and gives the dead control no href at all', function () {
    /** @var AdminTablePagedCapstoneTestCase $this */
    $first = (string) $this->get('/firefly/mappings?size=2')->assertStatus(200)->getContent();

    expect($first)->toContain('page 1 of 11')
        ->toMatch('~<a class="act off"\s*>Previous</a>~')
        ->toContain('<a class="act on" aria-label="Page 1" aria-current="page" href="/firefly/mappings?size=2">1</a>')
        ->toMatch('~<a class="act"\s+href="/firefly/mappings\?size=2&amp;page=2"\s*>Next</a>~');

    $last = (string) $this->get('/firefly/mappings?size=2&page=11')->assertStatus(200)->getContent();

    expect($last)->toContain('page 11 of 11')
        ->toMatch('~<a class="act off"\s*>Next</a>~')
        ->toMatch('~<a class="act"\s+href="/firefly/mappings\?size=2&amp;page=10"\s*>Previous</a>~');
});

// Eleven pages is the smallest listing on which a five-page window has a gap AND a jump on both sides.
it('draws a window around the current page, keeps the two ends, and says the rest is elided', function () {
    /** @var AdminTablePagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings?size=2&page=6')->assertStatus(200)->getContent();

    expect($body)->toContain('page 6 of 11')
        ->toContain('<a class="act on" aria-label="Page 6" aria-current="page" href="/firefly/mappings?size=2&amp;page=6">6</a>')
        // Two either side, and NOT a third: rendering every page of a long listing is a control nobody can use.
        ->toContain('>4</a>')
        ->toContain('>8</a>')
        ->not->toContain('>3</a>')
        ->not->toContain('>9</a>')
        // The two jumps people actually make are kept whatever the window is.
        ->toContain('<a class="act" aria-label="Page 1" aria-current="false" href="/firefly/mappings?size=2">1</a>')
        ->toContain('<a class="act" aria-label="Page 11" aria-current="false" href="/firefly/mappings?size=2&amp;page=11">11</a>');

    expect(substr_count($body, '<span class="gap">…</span>'))->toBe(2);
});

/**
 * The clamp, where it is actually observable.
 *
 * AdminTableListingTest asserts the same request renders rows rather than the empty state; it cannot assert
 * WHICH page it landed on, because a seven-row listing has one page and clamping to the last is
 * indistinguishable from clamping to the first. Eleven pages tells the two apart.
 */
it('clamps a page past the end onto the last page, not back onto the first', function () {
    /** @var AdminTablePagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings?page=9999&size=2')->assertStatus(200)->getContent();

    expect($body)->toContain('page 11 of 11')
        ->toContain('21–21 of 21')
        // The last row by path, so the response is the END of the listing and not its beginning.
        ->toContain('/widgets/21')
        ->not->toContain('No routes mapped')
        // Previous points one back from the page that was RENDERED — 10, never 9998.
        ->toMatch('~<a class="act"\s+href="/firefly/mappings\?size=2&amp;page=10"\s*>Previous</a>~');
});

/**
 * The OTHER state-carrying control in the pager, and the one that carries its state by hand.
 *
 * The first test in this file asserts the search survives every LINK; this one asserts it survives the
 * FORM, a separate mechanism with a separate way of going wrong. `ListingQuery::hiddenFields()` omits
 * `q`, because the form it was written for is the search form, whose own <input> supplies it — so the
 * rows-per-page form, which has no such input, has to re-add it itself. Delete that one line and an
 * operator who narrows a listing and then asks for more rows gets all twenty-one back with no search box
 * to explain it, and, before this test existed, with nothing failing either.
 *
 * `size` is the mirror image: hiddenFields() DOES offer it whenever it is not the default, and the <select>
 * owns it — two controls with the same name in one form submit the hidden one, so the select would be
 * decorative. Counting the name inside the form is what tells "the select owns it" from "a hidden input
 * shadows it", which no assertion about the select alone can see.
 */
it('carries the search into the rows-per-page form, and lets the select own the size alone', function () {
    /** @var AdminTablePagedCapstoneTestCase $this */
    $body = (string) $this->get('/firefly/mappings?q=orders&size=2&page=2')->assertStatus(200)->getContent();

    $form = Str::betweenFirst($body, '<div class="pager">', '</form>');

    expect($form)->toContain('<input type="hidden" name="q" value="orders">')
        ->toContain('<select name="size"')
        // `page` is absent on purpose: a listing resized from page 2 opens at the top, because page 2 of a
        // re-sliced result set is not the rows the reader was looking at.
        ->not->toContain('name="page"');

    expect(substr_count($form, 'name="size"'))->toBe(1);
});

/**
 * `N total` is the size of the RESULT SET, and only a listing that pages can tell that from the size of the
 * response.
 *
 * AdminTableListingTest pins the same label at `7 total` and `5 total`, but its fixture has one page, so
 * `$slice->total` and `count($slice->rows)` are the same number there and a header that started counting
 * the rows in front of it would still read correctly. Here they differ: page 2 of `?q=orders&size=2` holds
 * two rows out of five matches, so the header says five while the table shows two, and that disagreement is
 * the point — it is what tells a reader on page 2 how much more there is.
 */
it('counts the whole result set in the header, not the rows on the page', function () {
    /** @var AdminTablePagedCapstoneTestCase $this */
    $this->get('/firefly/mappings?q=orders&size=2&page=2')
        ->assertStatus(200)
        ->assertSee('5 total', false)
        ->assertSee('3–4 of 5', false);
});
