<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataColumn;
use Firefly\Admin\Data\DataSchema;
use Firefly\Admin\Tests\Data\Fixtures\AdminRecord;
use Firefly\Admin\Tests\Data\Fixtures\AdminRecordRepository;
use Firefly\Admin\Tests\Data\Fixtures\Alt\AdminRecordRepository as AltAdminRecordRepository;
use Firefly\Admin\Tests\Data\Fixtures\PlainNote;
use Firefly\Admin\Tests\Data\Fixtures\PlainNoteRepository;
use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;

uses(DataBrowserTestCase::class);

it('finds every CrudRepository bean and nothing else', function () {
    /** @var DataBrowserTestCase $this */
    $slugs = array_map(fn ($resource) => $resource->slug, $this->browser()->resources());

    expect($slugs)->toBe(['admin-record', 'plain-note']);
});

it('reads each resource capability from the catalogue and the declared model', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browser();

    $record = $this->resourceOf($browser, 'admin-record');
    expect($record->label)->toBe('Admin Record')
        ->and($record->repositoryClass)->toBe(AdminRecordRepository::class)
        ->and($record->entityClass)->toBe(AdminRecord::class)
        ->and($record->table)->toBe('admin_records')
        ->and($record->paged)->toBeTrue()
        ->and($record->eloquent)->toBeTrue()
        ->and($record->isEloquentBacked())->toBeTrue();

    // No $model, so the entity comes from findById()'s narrowed return type; CrudRepository only, so no paging.
    $note = $this->resourceOf($browser, 'plain-note');
    expect($note->repositoryClass)->toBe(PlainNoteRepository::class)
        ->and($note->entityClass)->toBe(PlainNote::class)
        ->and($note->table)->toBeNull()
        ->and($note->paged)->toBeFalse()
        ->and($note->eloquent)->toBeFalse();
});

it('derives columns from the live schema, refined by the model casts', function () {
    /** @var DataBrowserTestCase $this */
    $schema = $this->schemaOf($this->browser(), 'admin-record');

    expect($schema->source)->toBe(DataSchema::SOURCE_SCHEMA)
        ->and($schema->names())->toBe(['id', 'email', 'api_token', 'recovery_phrase', 'amount', 'active', 'meta', 'created_at'])
        ->and($schema->identifier)->toBe('id');

    $types = [];
    foreach ($schema->columns as $column) {
        $types[$column->name] = $column->type;
    }

    // sqlite reports `text` for meta and `tinyint` for active; only the model's casts know what they mean.
    expect($types)->toBe([
        'id' => DataColumn::TYPE_INT,
        'email' => DataColumn::TYPE_STRING,
        'api_token' => DataColumn::TYPE_STRING,
        'recovery_phrase' => DataColumn::TYPE_STRING,
        'amount' => DataColumn::TYPE_INT,
        'active' => DataColumn::TYPE_BOOL,
        'meta' => DataColumn::TYPE_JSON,
        'created_at' => DataColumn::TYPE_DATETIME,
    ]);
});

it('marks the identifier and reports nullability from the schema', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browser();

    expect($this->columnOf($browser, 'admin-record', 'id')->identifier)->toBeTrue()
        ->and($this->columnOf($browser, 'admin-record', 'id')->nullable)->toBeFalse()
        ->and($this->columnOf($browser, 'admin-record', 'email')->identifier)->toBeFalse()
        ->and($this->columnOf($browser, 'admin-record', 'email')->nullable)->toBeFalse()
        ->and($this->columnOf($browser, 'admin-record', 'api_token')->nullable)->toBeTrue()
        ->and($this->schemaOf($browser, 'admin-record')->identifierColumn()?->name)->toBe('id');
});

it('flags a secret by name AND a column the model hides', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browser();

    // `api_token` matches the actuator masker's regex; `recovery_phrase` matches nothing, and is only a
    // secret because the model put it in $hidden.
    expect($this->columnOf($browser, 'admin-record', 'api_token')->sensitive)->toBeTrue()
        ->and($this->columnOf($browser, 'admin-record', 'api_token')->isEditable())->toBeFalse()
        ->and($this->columnOf($browser, 'admin-record', 'recovery_phrase')->sensitive)->toBeTrue()
        ->and($this->columnOf($browser, 'admin-record', 'email')->sensitive)->toBeFalse()
        ->and($this->columnOf($browser, 'admin-record', 'email')->isEditable())->toBeTrue()
        ->and($this->columnOf($browser, 'admin-record', 'id')->isEditable())->toBeFalse();
});

it('derives columns of a plain entity from its promoted constructor parameters', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browser();
    $schema = $this->schemaOf($browser, 'plain-note');

    expect($schema->source)->toBe(DataSchema::SOURCE_ENTITY)
        // `id` is promoted PROTECTED — a public-properties-only scan would have lost the identifier.
        ->and($schema->names())->toBe(['id', 'title', 'body', 'pinned'])
        ->and($schema->identifier)->toBe('id')
        ->and($this->columnOf($browser, 'plain-note', 'id')->type)->toBe(DataColumn::TYPE_INT)
        ->and($this->columnOf($browser, 'plain-note', 'body')->nullable)->toBeTrue()
        ->and($this->columnOf($browser, 'plain-note', 'title')->nullable)->toBeFalse()
        ->and($this->columnOf($browser, 'plain-note', 'pinned')->type)->toBe(DataColumn::TYPE_BOOL);
});

it('excludes json from sortable columns and secrets from searchable ones', function () {
    /** @var DataBrowserTestCase $this */
    $schema = $this->schemaOf($this->browser(), 'admin-record');

    expect($schema->sortable())->not->toContain('meta')
        ->and($schema->sortable())->toContain('id')
        ->and($schema->searchable())->toBe(['email'])
        ->and($schema->searchable())->not->toContain('api_token');
});

it('hides an excluded resource from discovery and from every operation', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browser(['enabled' => true, 'writable' => true, 'exclude' => 'admin-record']);

    expect(array_map(fn ($r) => $r->slug, $browser->resources()))->toBe(['plain-note'])
        ->and($browser->resource('admin-record'))->toBeNull()
        ->and($browser->schema('admin-record'))->toBeNull()
        ->and($browser->find('admin-record', 1))->toBeNull()
        ->and($browser->list('admin-record')->error)->toBe('No such resource.')
        ->and($browser->delete('admin-record', 1)->isNotFound())->toBeTrue();
});

// Two bounded contexts each owning an `AdminRecord` is ordinary. A `-2` suffix would have made ONE of them
// depend on scan order, so a removal elsewhere could silently repoint a bookmarked URL at a different table.
it('qualifies BOTH sides of a slug collision instead of suffixing one', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserOver([AdminRecordRepository::class, AltAdminRecordRepository::class]);

    $slugs = array_map(fn ($resource) => $resource->slug, $browser->resources());
    expect($slugs)->toBe([
        'firefly-admin-tests-data-fixtures-admin-record',
        'firefly-admin-tests-data-fixtures-alt-admin-record',
    ])
        ->and($slugs)->not->toContain('admin-record');

    $labels = array_map(fn ($resource) => $resource->label, $browser->resources());
    expect($labels[0])->toBe('Admin Record (Firefly\\Admin\\Tests\\Data\\Fixtures)')
        ->and($labels[1])->toBe('Admin Record (Firefly\\Admin\\Tests\\Data\\Fixtures\\Alt)')
        ->and($this->resourceOf($browser, 'firefly-admin-tests-data-fixtures-alt-admin-record')->table)->toBe('alt_admin_records');
});

// The catalogue is bound by the actuator's own registrar. Without it there is no discovery source, and the
// honest answer is "no resources" rather than a reflective scan that would find classes nothing wired.
it('reports no resources when no beans catalogue is bound', function () {
    /** @var DataBrowserTestCase $this */
    $browser = $this->browserWithoutCatalog();

    expect($browser->isEnabled())->toBeTrue()
        ->and($browser->resources())->toBe([])
        ->and($browser->resource('admin-record'))->toBeNull()
        ->and($browser->list('admin-record')->error)->toBe('No such resource.');
});
