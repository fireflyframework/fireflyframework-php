<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Tests\Data\Fixtures\AdminEntry;
use Firefly\Admin\Tests\Data\Fixtures\AdminRecordRepository;
use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;

uses(DataBrowserTestCase::class);

/**
 * Walking from a record to the rows it is related to — the half of a data browser that turns a table dump
 * into something you can explore.
 *
 * The relation is discovered by CALLING the model's method, which is the only way to learn which columns it
 * joins on: a name says nothing and a return type says only the kind. That makes "which methods are safe to
 * call" the load-bearing question, and it is answered by the declared return type — a method announcing
 * `: HasMany` is a relation definition by construction. AdminEntry deliberately also declares a plain
 * accessor and a method that MUTATES a static counter, so the boundary is asserted rather than assumed.
 */
beforeEach(function () {
    AdminEntry::$calls = 0;
});

it('finds both directions of a relation and resolves each to a browsable resource', function () {
    /** @var DataBrowserTestCase $this */
    $parent = $this->relatedBrowser()->relationsFor('admin-record');
    $child = $this->relatedBrowser()->relationsFor('admin-entry');

    expect($parent)->toHaveCount(1)
        ->and($parent[0]->kind)->toBe('HasMany')
        ->and($parent[0]->toMany)->toBeTrue()
        // The key is on the CHILD table and points back here, which is what makes "the entries of record 1"
        // a filter on the child listing rather than a lookup on this row.
        ->and($parent[0]->column)->toBe('record_id')
        ->and($parent[0]->target)->toBe('id')
        ->and($parent[0]->relatedSlug)->toBe('admin-entry')
        ->and($parent[0]->navigable())->toBeTrue();

    expect($child)->toHaveCount(1)
        ->and($child[0]->kind)->toBe('BelongsTo')
        ->and($child[0]->toMany)->toBeFalse()
        // The other way round: the key is on THIS row.
        ->and($child[0]->column)->toBe('record_id')
        ->and($child[0]->target)->toBe('id')
        ->and($child[0]->relatedSlug)->toBe('admin-record');
});

it('calls only the methods that declare a relation return type', function () {
    /** @var DataBrowserTestCase $this */
    $this->relatedBrowser()->relationsFor('admin-entry');

    // AdminEntry::touchedCount() is public, takes no arguments, and increments a static. If discovery ever
    // widened past "the declared return type is a Relation", this is the counter that would move — and the
    // failure would be arbitrary application code running on a dashboard page load.
    expect(AdminEntry::$calls)->toBe(0);
});

it('is not navigable when the other end is not a browsable resource', function () {
    /** @var DataBrowserTestCase $this */
    // A catalogue with only the parent: the relation still exists and is still worth showing, but there is
    // nowhere for a link to go. Distinguished here rather than in the view so a template cannot mint a URL
    // that 404s.
    $relations = $this->browserOver([AdminRecordRepository::class], ['enabled' => true])->relationsFor('admin-record');

    expect($relations)->toHaveCount(1)
        ->and($relations[0]->relatedSlug)->toBeNull()
        ->and($relations[0]->navigable())->toBeFalse();
});

it('narrows a listing to the rows on the other end of a relation', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $this->seedEntries();

    $all = $this->relatedBrowser()->list('admin-entry');
    $mine = $this->relatedBrowser()->list('admin-entry', filter: new DataFilter('record_id', '1'));

    expect($all->total)->toBe(3)
        ->and($mine->total)->toBe(2)
        ->and($mine->filter?->column)->toBe('record_id')
        ->and(array_column($mine->rows, 'note'))->toBe(['first for ada', 'second for ada']);
});

it('combines a filter with a search rather than letting either escape the other', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $this->seedEntries();

    // Searching inside a relation's listing must NARROW it. An OR here would answer "every entry matching
    // 'ada', plus every entry of record 1" — which shows a reader rows from outside the relation they are
    // looking at.
    $listing = $this->relatedBrowser()->list('admin-entry', search: 'second', filter: new DataFilter('record_id', '1'));

    expect($listing->total)->toBe(1)
        ->and($listing->rows[0]['note'])->toBe('second for ada');

    expect($this->relatedBrowser()->list('admin-entry', search: 'only', filter: new DataFilter('record_id', '1'))->total)->toBe(0);
});

it('drops a filter naming a column the resource does not have', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $this->seedEntries();

    // The column arrives in a URL an operator can hand-edit. A query that reached the driver with an
    // arbitrary identifier in it is a column-name oracle at best, so an unknown column is dropped and the
    // listing widens rather than erroring — which also tells the caller nothing about what does exist.
    $listing = $this->relatedBrowser()->list('admin-entry', filter: new DataFilter('no_such_column', '1'));

    expect($listing->failed())->toBeFalse()
        ->and($listing->total)->toBe(3)
        ->and($listing->filter)->toBeNull();
});

it('offers no relations when the browser or the feature is switched off', function () {
    /** @var DataBrowserTestCase $this */
    expect($this->relatedBrowser(['enabled' => false])->relationsFor('admin-record'))->toBe([])
        ->and($this->relatedBrowser(['enabled' => true, 'relations' => false])->relationsFor('admin-record'))->toBe([]);
});
