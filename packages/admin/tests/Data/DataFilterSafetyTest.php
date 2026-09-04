<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;
use Illuminate\Support\Facades\DB;

uses(DataBrowserTestCase::class);

/**
 * The two ways a filter can betray the browser's own promises, both found by an adversarial review of the
 * branch that introduced filtering and both reproduced before they were fixed.
 */
it('cannot be used to extract a masked column one character at a time', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();

    // THE ORIGINAL ATTACK, verbatim. A masked column renders as `******`, but a filter over it answers a
    // yes/no question about the REAL value — and a yes/no question you can ask repeatedly is an extraction
    // oracle. Before the fix this loop recovered `correct horse battery` in twenty-one rounds while the
    // listing showed nothing but asterisks.
    $alphabet = array_merge(range('a', 'z'), [' ']);
    $recovered = '';

    for ($i = 0; $i < 21; $i++) {
        foreach ($alphabet as $character) {
            $listing = $browser->list('admin-record', filters: [
                new DataFilter('recovery_phrase', DataFilter::STARTS, $recovered.$character),
            ]);

            if ($listing->total === 1) {
                $recovered .= $character;
                break;
            }
        }
    }

    expect($recovered)->toBe('')
        // The filter is DROPPED, so the listing widens rather than erroring — which also means the attacker
        // learns nothing from the difference between "no such column" and "no rows".
        ->and($browser->list('admin-record')->rows[0]['recovery_phrase'])->toBe('******');
});

it('drops a filter on any sensitive column, whatever the comparison', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();

    // `>` and `<` are an oracle too, and a cheaper one: a binary search over the value space needs far fewer
    // rounds than walking the alphabet. Every operator is refused, not just the LIKE ones.
    foreach ([DataFilter::EQ, DataFilter::NE, DataFilter::CONTAINS, DataFilter::STARTS, DataFilter::GT, DataFilter::LT, DataFilter::NULL, DataFilter::NOT_NULL] as $operator) {
        foreach (['api_token', 'recovery_phrase'] as $column) {
            $listing = $browser->list('admin-record', filters: [new DataFilter($column, $operator, 'sk_live')]);

            expect($listing->filters)->toBe([])
                ->and($listing->total)->toBe(5);
        }
    }
});

it('still filters on the ordinary columns, including the non-string ones', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser();

    // The fix must not have closed the feature. `filterable()` is deliberately wider than `searchable()`:
    // filtering an int or a datetime is the ordinary case, and `>`/`<` exist for exactly them.
    expect($browser->list('admin-record', filters: [new DataFilter('amount', DataFilter::GT, '200')])->total)->toBe(3)
        ->and($browser->list('admin-record', filters: [new DataFilter('active', DataFilter::EQ, '1')])->total)->toBe(3)
        ->and($browser->list('admin-record', filters: [new DataFilter('email', DataFilter::CONTAINS, 'grace')])->total)->toBe(1)
        ->and($browser->list('admin-record', filters: [new DataFilter('meta', DataFilter::NULL)])->total)->toBe(3);
});

it('treats a LIKE metacharacter in the value as a literal, and still finds it', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    DB::table('admin_records')->where('id', 1)->update(['email' => 'ada_lovelace@example.test']);
    $browser = $this->browser();

    // ESCAPING WITHOUT AN `ESCAPE` CLAUSE IS WORSE THAN NOT ESCAPING. Backslash-escaping `_` and then
    // emitting a plain `LIKE ?` leaves the driver with no escape character declared, so `ada\_love` is
    // matched literally and a search for `ada_love` returned ZERO rows against a table that contained
    // `ada_lovelace@example.test`. Suppressing the wildcards worked; finding an underscore stopped working,
    // silently — which is the worse of the two failures.
    expect($browser->list('admin-record', filters: [new DataFilter('email', DataFilter::CONTAINS, 'ada_love')])->total)->toBe(1)
        // And the wildcards are still suppressed: a `%` matches a literal percent sign, not everything.
        ->and($browser->list('admin-record', filters: [new DataFilter('email', DataFilter::CONTAINS, '%')])->total)->toBe(0)
        ->and($browser->list('admin-record', filters: [new DataFilter('email', DataFilter::CONTAINS, 'ada%love')])->total)->toBe(0)
        ->and($browser->list('admin-record', filters: [new DataFilter('email', DataFilter::STARTS, 'ada_love')])->total)->toBe(1);
});

it('applies the same escaping to the search box', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    DB::table('admin_records')->where('id', 1)->update(['email' => 'ada_lovelace@example.test']);
    $browser = $this->browser();

    // The search path had no escaping at all, so a `%` matched every row. Leaving one path escaped and the
    // other not would have been worse than either: the same term would mean different things in two boxes on
    // the same page.
    expect($browser->list('admin-record', search: '%')->total)->toBe(0)
        ->and($browser->list('admin-record', search: 'ada_love')->total)->toBe(1)
        ->and($browser->list('admin-record', search: "o'brien")->total)->toBe(1);
});
