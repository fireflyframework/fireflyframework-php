<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\BrowserTestCase;
use Illuminate\Support\Facades\DB;

pest()->extend(BrowserTestCase::class);

it('lists the repositories, then the seeded rows, and a filter narrows them', function (): void {
    /** @var BrowserTestCase $this */
    $this->seedOrders();

    visit('/firefly/data')
        ->assertSee('Order Entity')
        ->assertSee('Order Line Entity')
        ->assertSee('paged')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-index');

    $list = visit('/firefly/data?resource=order-entity');

    $list->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper')
        ->assertSee('Margaret Hamilton')
        ->assertSee('3 total')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-list');

    // The filter bar is a <details class="panel filters"> that is CLOSED on an unfiltered listing;
    // Playwright waits for actionability, so its controls must be revealed before they are driven.
    $list->click('details.filters > summary')
        ->select('fc[]', 'customer')
        ->select('fo[]', 'contains')
        ->fill('fv[]', 'Hopper')
        ->press('Apply')
        // parse_str turns fv[]=Hopper into fv => ['Hopper']; the plugin compares the raw value, so only
        // the parameter's presence is asserted here and the narrowing is proved by the rows below.
        ->assertQueryStringHas('fv')
        ->assertSee('1 total')
        ->assertSee('Grace Hopper')
        ->assertDontSee('Ada Lovelace')
        ->assertDontSee('Margaret Hamilton')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-list-filtered');
});

it('opens a record, edits it, and the row changes', function (): void {
    /** @var BrowserTestCase $this */
    [$ada] = $this->seedOrders();

    $record = visit('/firefly/data?resource=order-entity&id='.$ada);

    $record->assertSee('Order Entity')
        ->assertSee('#'.$ada)
        ->assertSee('ada@example.com')
        ->assertSee('Lines')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-record');

    $record->fill('[name="f[customer]"]', 'Ada King, Countess of Lovelace')
        ->press('Save changes')
        ->assertSee('Ada King, Countess of Lovelace')
        // The outcome sentence rides the session across the redirect — a real cookie round trip, which is
        // the part a request-level test cannot make: it was silently lost while AdminAction asked the
        // session MANAGER whether it was a store.
        ->assertSee('Updated 1 field(s).')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-record-saved');

    // The same SQLite connection the plugin's in-process server wrote through.
    expect(DB::table('orders')->where('id', $ada)->value('customer'))->toBe('Ada King, Countess of Lovelace');
});

it('creates a record from the form, and deletes one after confirming', function (): void {
    /** @var BrowserTestCase $this */
    [$ada] = $this->seedOrders();

    $new = visit('/firefly/data?resource=order-entity&new=1');

    $new->assertSee('New order entity')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-new');

    // Only the three columns a person has to type. `total` is NOT NULL DEFAULT 0 and the timestamps are
    // Eloquent's: the form submits them blank, and the create must leave them to the schema and the model.
    $new->fill('[name="f[customer]"]', 'Katherine Johnson')
        ->fill('[name="f[email]"]', 'katherine@example.com')
        ->fill('[name="f[ship_to]"]', '{"street":"1 Orbit Way","city":"Hampton","postcode":"23666","country":"US"}')
        ->press('Create record')
        ->assertSee('Katherine Johnson')
        ->assertSee('Created.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-created');

    $katherine = DB::table('orders')->where('customer', 'Katherine Johnson');
    expect($katherine->value('email'))->toBe('katherine@example.com')
        // The database default, not a typed value; the model's clock, not an explicit null from the form.
        ->and($katherine->value('total'))->toEqual(0)
        ->and($katherine->value('created_at'))->not->toBeNull()
        ->and(DB::table('orders')->count())->toBe(4);

    // The delete form asks `window.confirm`, and Playwright dismisses dialogs by default — which would
    // silently NOT submit. Answering yes from the page is the honest browser equivalent of clicking OK.
    // `script()` returns the evaluated value, never the page, so it is its own statement.
    $record = visit('/firefly/data?resource=order-entity&id='.$ada);
    $record->script('window.confirm = function () { return true; };');
    $record->press('Delete this record')
        ->assertDontSee('ada@example.com')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-list-after-delete');

    expect(DB::table('orders')->where('id', $ada)->exists())->toBeFalse()
        ->and(DB::table('orders')->count())->toBe(3);
});

it('walks a relation from the record to the filtered child list', function (): void {
    /** @var BrowserTestCase $this */
    [$ada] = $this->seedOrders();

    visit('/firefly/data?resource=order-entity&id='.$ada)
        ->click('Browse →')
        ->assertQueryStringHas('resource', 'order-line-entity')
        ->assertQueryStringHas('fk', 'order_id')
        ->assertQueryStringHas('fv', (string) $ada)
        // Filtered, not merely listed: only Ada's line is on the page.
        ->assertSee('1 total')
        ->assertSee('ONLY-ADA')
        ->assertDontSee('ONLY-GRACE')
        ->assertDontSee('ONLY-MARGARET')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'data-relation');
});
