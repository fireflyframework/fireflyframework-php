<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataColumn;
use Firefly\Admin\Data\DataSchema;
use Firefly\Admin\Data\DataWriteOutcome;
use Firefly\Admin\Tests\Data\Fixtures\Alt\AdminRecordRepository as AltAdminRecordRepository;
use Firefly\Admin\Tests\Data\Fixtures\BrokenRepository;
use Firefly\Admin\Tests\Data\Fixtures\GhostRecordRepository;
use Firefly\Admin\Tests\Data\Fixtures\NotARepository;
use Firefly\Admin\Tests\Data\Fixtures\OrphanRecordRepository;
use Firefly\Admin\Tests\Data\Fixtures\PairRepository;
use Firefly\Admin\Tests\Data\Fixtures\ScopedNoteRepository;
use Firefly\Admin\Tests\Data\Fixtures\WidgetRepository;
use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;
use Firefly\Data\Repository\CrudRepository;
use Illuminate\Support\Facades\DB;

uses(DataBrowserTestCase::class);

/**
 * The edges the happy-path files do not reach: the projector's non-scalar branches, the resources that
 * degrade (no identifier, no table, no constructible bean), and the coercions that must be refused.
 */

// `deleteById()` returns void, so a repository that removed nothing is indistinguishable from one that
// removed the row unless the browser goes back and looks.
it('reports a delete the repository silently declined instead of claiming success', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();
    $browser = $this->browserOver([ScopedNoteRepository::class], ['enabled' => true, 'writable' => true]);

    // Note 2 is not pinned, so this repository's scoped delete matches nothing at all.
    $declined = $browser->delete('plain-note', 2);

    expect($declined->outcome)->toBe(DataWriteOutcome::Failed)
        ->and($declined->reason)->toBe('The repository accepted the delete but the record is still present.')
        ->and(DB::table('admin_notes')->where('id', 2)->exists())->toBeTrue();

    // Note 1 is pinned, so the same code path succeeds and says so.
    expect($browser->delete('plain-note', 1)->isDone())->toBeTrue()
        ->and(DB::table('admin_notes')->where('id', 1)->exists())->toBeFalse();
});

it('reduces a datetime, an enum, an array, a value object and an opaque object to printable values', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedWidgets();
    $browser = $this->browserOver([WidgetRepository::class]);

    $row = $browser->list('widget')->rows[0];

    expect($row['occurredAt'])->toBe('2026-02-01 09:30:00')
        ->and($row['status'])->toBe('live')
        ->and($row['tags'])->toBe('["a","b"]')
        ->and($row['price'])->toBe('10.10 EUR')
        // Nothing printable, so the class name rather than an "Object of class X" fatal.
        ->and($row['opaque'])->toBe('[stdClass]');

    // The detail view reduces them identically.
    expect($this->recordOf($browser, 'widget', 'w-1')->fields['status'])->toBe('live');
});

it('falls through the identifier preference order to uuid when there is no id', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedWidgets();
    $browser = $this->browserOver([WidgetRepository::class]);
    $schema = $this->schemaOf($browser, 'widget');

    expect($schema->source)->toBe(DataSchema::SOURCE_ENTITY)
        ->and($schema->identifier)->toBe('uuid')
        ->and($schema->identifierColumn()?->identifier)->toBeTrue()
        ->and($this->columnOf($browser, 'widget', 'occurredAt')->type)->toBe(DataColumn::TYPE_DATETIME)
        ->and($this->columnOf($browser, 'widget', 'tags')->type)->toBe(DataColumn::TYPE_JSON)
        // The listing is ordered by that identifier, not left to the repository's whim.
        ->and($browser->list('widget')->sort)->toBe('uuid');
});

// A resource whose key cannot be derived is browsable as a list and nothing else. Guessing a column here
// would mean a DELETE whose WHERE clause matched rows nobody asked about.
it('refuses to address a single row of a resource with no identifier', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedPairs();
    $browser = $this->browserOver([PairRepository::class], ['enabled' => true, 'writable' => true]);

    $schema = $this->schemaOf($browser, 'pair');
    $listing = $browser->list('pair');

    expect($schema->identifier)->toBeNull()
        ->and($schema->identifierColumn())->toBeNull()
        ->and($listing->failed())->toBeFalse()
        ->and($listing->total)->toBe(2)
        // Nothing sortable was derivable either, so the listing goes out unordered rather than on a guess.
        ->and($listing->sort)->toBeNull()
        ->and($browser->find('pair', 'alpha'))->toBeNull()
        ->and($browser->delete('pair', 'alpha')->reason)->toBe('This resource has no identifier column, so a single record cannot be addressed.')
        ->and($browser->delete('pair', 'alpha')->outcome)->toBe(DataWriteOutcome::Refused)
        // The reason matters as much as the outcome: without the identifier check this would still be
        // refused, but for the wrong reason ("not an Eloquent model"), and delete would have run.
        ->and($browser->update('pair', 'alpha', ['right' => 'x'])->reason)->toBe('This resource has no identifier column, so a single record cannot be addressed.')
        ->and(DB::table('pairs')->count())->toBe(2);
});

// The binding is real — it came from the catalogue — but running the constructor is what fails.
it('states a reason when the repository bean cannot be constructed', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserOver([BrokenRepository::class]);

    // No narrowed return type anywhere, so no entity is inferred and the resource is named after the
    // repository with the conventional suffix stripped.
    $resource = $this->resourceOf($browser, 'broken');
    $listing = $browser->list('broken');

    expect($resource->entityClass)->toBeNull()
        ->and($resource->shortName())->toBe('BrokenRepository')
        ->and($listing->failed())->toBeTrue()
        ->and($listing->error)->toBe('The repository bean for this resource could not be resolved from the container.')
        ->and($listing->columns())->toBe([])
        ->and($browser->find('broken', 1))->toBeNull();
});

it('exposes the schema columns of a listing, in order, for the view to draw', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $listing = $this->browser()->list('admin-record');

    expect(array_map(fn (DataColumn $c) => $c->name, $listing->columns()))
        ->toBe(['id', 'email', 'api_token', 'recovery_phrase', 'amount', 'active', 'meta', 'created_at'])
        ->and($listing->columns())->toEqual($this->schemaOf($this->browser(), 'admin-record')->columns);
});

it('degrades a model whose table is missing to a key-only listing that says so', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserOver([GhostRecordRepository::class]);
    $schema = $this->schemaOf($browser, 'ghost-record');

    expect($schema->source)->toBe(DataSchema::SOURCE_NONE)
        ->and($schema->isEmpty())->toBeFalse()
        ->and($schema->names())->toBe(['id'])
        ->and($schema->identifier)->toBe('id')
        // The resource stays in the menu instead of vanishing with no explanation.
        ->and(array_map(fn ($r) => $r->slug, $browser->resources()))->toBe(['ghost-record']);
});

// `active` is the case the casts are usually credited with: sqlite spells `boolean()` as `tinyint(1)`, which
// the driver type map already reads correctly. This model declares no casts at all, so nothing else can.
it('types a column from the driver alone when the model declares no casts', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserOver([AltAdminRecordRepository::class]);
    $schema = $this->schemaOf($browser, 'admin-record');

    $types = [];
    foreach ($schema->columns as $column) {
        $types[$column->name] = $column->type;
    }

    expect($schema->source)->toBe(DataSchema::SOURCE_SCHEMA)
        ->and($types)->toBe([
            'id' => DataColumn::TYPE_INT,
            'label' => DataColumn::TYPE_STRING,
            'archived' => DataColumn::TYPE_BOOL,
            'rank' => DataColumn::TYPE_INT,
        ]);
});

it('refuses a value whose PHP type the column cannot hold', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser(['enabled' => true, 'writable' => true]);

    // An array posted at a column that is not JSON is not a value, it is a malformed submission.
    expect($browser->update('admin-record', 1, ['email' => ['a', 'b']])->outcome)->toBe(DataWriteOutcome::Refused)
        // An explicit null at a NOT NULL column would be a constraint violation dressed up as an edit.
        ->and($browser->update('admin-record', 1, ['amount' => null])->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($browser->update('admin-record', 1, ['amount' => null])->reason)->toContain('amount')
        // A nullable one takes it.
        ->and($browser->update('admin-record', 1, ['api_token' => null])->isDone())->toBeTrue()
        ->and(DB::table('admin_records')->where('id', 1)->value('email'))->toBe('ada@example.test')
        ->and(DB::table('admin_records')->where('id', 1)->value('amount'))->toBe(50);
});

it('treats a blank search as no search at all', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $listing = $this->browser()->list('admin-record', search: "   \t ");

    expect($listing->search)->toBeNull()
        ->and($listing->total)->toBe(5);
});

// The in-PHP fallback has to order nulls somewhere, and "wherever the comparison happens to put them" leaves
// empties scattered through the values.
it('sorts nulls last in the in-PHP fallback', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();

    // Gamma is the row with no body.
    expect(array_column($this->browser()->list('plain-note', sort: 'body')->rows, 'title'))->toBe(['Alpha', 'Beta', 'Gamma']);
});

it('degrades a type outside the closed vocabulary to string rather than passing it to the view', function () {
    expect(DataColumn::of('whatever', 'numeric')->type)->toBe(DataColumn::TYPE_STRING)
        ->and(DataColumn::of('whatever', DataColumn::TYPE_JSON)->type)->toBe(DataColumn::TYPE_JSON)
        ->and(DataColumn::of('api_token')->sensitive)->toBeTrue();
});

it('degrades a model whose connection is not configured to a key-only listing', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserOver([OrphanRecordRepository::class]);
    $schema = $this->schemaOf($browser, 'orphan-record');

    // Asking the schema builder for columns throws here; the key name is known without a connection, and it
    // is the one column every other operation needs.
    expect($schema->source)->toBe(DataSchema::SOURCE_NONE)
        ->and($schema->names())->toBe(['id'])
        ->and($schema->identifier)->toBe('id')
        ->and($this->resourceOf($browser, 'orphan-record')->isEloquentBacked())->toBeTrue();
});

// BeansCatalog is a COMPILED snapshot, so it can be stale: a row may still claim an interface the class
// stopped implementing. Trusting the row and handing the caller whatever the container returned would put a
// non-repository through the query engine.
it('refuses a bean the stale catalogue calls a repository but the container does not', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserOverStaleCatalog([NotARepository::class => [CrudRepository::class]]);

    $listing = $browser->list('not-a');

    expect(array_map(fn ($r) => $r->slug, $browser->resources()))->toBe(['not-a'])
        ->and($listing->failed())->toBeTrue()
        ->and($listing->error)->toBe('The repository bean for this resource could not be resolved from the container.')
        ->and($browser->find('not-a', 1))->toBeNull();
});
