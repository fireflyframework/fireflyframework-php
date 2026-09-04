<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataBrowser;
use Firefly\Admin\Data\DataWriteOutcome;
use Firefly\Admin\Tests\Data\Support\DataBrowserTestCase;
use Illuminate\Support\Facades\DB;

uses(DataBrowserTestCase::class);

/**
 * Both gates open.
 *
 * @return array<string, mixed>
 */
function writableData(): array
{
    return ['enabled' => true, 'writable' => true];
}

it('refuses every write while the browser is switched off', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser([]);

    $deleted = $browser->delete('admin-record', 1);
    $updated = $browser->update('admin-record', 1, ['email' => 'x@y.test']);

    expect($deleted->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($deleted->reason)->toContain('firefly.admin.data.enabled')
        // An operator who never switched the browser on is not told that a write key exists.
        ->and($deleted->reason)->not->toContain('writable')
        ->and($updated->isRefused())->toBeTrue()
        ->and(DB::table('admin_records')->count())->toBe(5);
});

it('refuses every write while the browser is read-only, naming the key that would open it', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser(['enabled' => true]);

    $deleted = $browser->delete('admin-record', 1);
    $updated = $browser->update('admin-record', 1, ['email' => 'x@y.test']);

    expect($browser->isEnabled())->toBeTrue()
        ->and($browser->isWritable())->toBeFalse()
        ->and($deleted->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($deleted->reason)->toContain('firefly.admin.data.writable')
        ->and($updated->outcome)->toBe(DataWriteOutcome::Refused)
        ->and(DB::table('admin_records')->where('id', 1)->value('email'))->toBe('ada@example.test')
        ->and(DB::table('admin_records')->count())->toBe(5);
});

it('deletes a row through the repository when both gates are open', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $result = $this->browser(writableData())->delete('admin-record', 2);

    expect($result->outcome)->toBe(DataWriteOutcome::Done)
        ->and($result->reason)->toBe('Deleted.')
        ->and($result->id)->toBe(2)
        ->and($result->resource)->toBe('admin-record')
        ->and(DB::table('admin_records')->count())->toBe(4)
        ->and(DB::table('admin_records')->where('id', 2)->exists())->toBeFalse();
});

it('deletes through a plain CrudRepository too', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();

    expect($this->browser(writableData())->delete('plain-note', 3)->isDone())->toBeTrue()
        ->and(DB::table('admin_notes')->count())->toBe(2);
});

it('reports a missing row and a missing resource as NOT FOUND, never as a failure', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();
    $browser = $this->browser(writableData());

    expect($browser->delete('admin-record', 999)->outcome)->toBe(DataWriteOutcome::NotFound)
        ->and($browser->delete('admin-record', 999)->reason)->toBe('No such record.')
        ->and($browser->delete('nope', 1)->outcome)->toBe(DataWriteOutcome::NotFound)
        ->and($browser->update('admin-record', 999, ['email' => 'x@y.test'])->outcome)->toBe(DataWriteOutcome::NotFound);
});

it('updates named columns and reports exactly which ones it wrote', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $result = $this->browser(writableData())->update('admin-record', 1, [
        'email' => 'ada.lovelace@example.test',
        'amount' => '999',
        'active' => '0',
    ]);

    expect($result->outcome)->toBe(DataWriteOutcome::Done)
        ->and($result->changed)->toBe(['email', 'amount', 'active'])
        ->and($result->reason)->toBe('Updated 3 field(s).');

    $row = DB::table('admin_records')->where('id', 1);
    expect($row->value('email'))->toBe('ada.lovelace@example.test')
        // Submitted as strings by a form, stored as the column's own type.
        ->and($row->value('amount'))->toBe(999)
        ->and($row->value('active'))->toBe(0);
});

it('drops the identifier and every masked column instead of writing the mask over the secret', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    // Exactly what a detail form that round-trips every field it rendered would post back.
    $result = $this->browser(writableData())->update('admin-record', 1, [
        'id' => '42',
        'api_token' => '******',
        'recovery_phrase' => '******',
        'email' => 'changed@example.test',
    ]);

    expect($result->isDone())->toBeTrue()
        ->and($result->changed)->toBe(['email']);

    $row = DB::table('admin_records')->where('email', 'changed@example.test');
    expect($row->value('id'))->toBe(1)
        ->and($row->value('api_token'))->toBe('sk_live_ada_secret')
        ->and($row->value('recovery_phrase'))->toBe('correct horse battery')
        ->and(DB::table('admin_records')->where('id', 42)->exists())->toBeFalse();
});

it('refuses a submission carrying a column this resource does not have', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $result = $this->browser(writableData())->update('admin-record', 1, ['email' => 'x@y.test', 'is_admin' => '1']);

    expect($result->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($result->reason)->toContain('are not columns of this resource')
        // Nothing at all is written when part of the submission is rejected.
        ->and(DB::table('admin_records')->where('id', 1)->value('email'))->toBe('ada@example.test');
});

it('refuses a value that is not of the column type', function (string $column, string $value) {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $result = $this->browser(writableData())->update('admin-record', 1, [$column => $value]);

    expect($result->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($result->reason)->toContain($column);
})->with([
    'a non-numeric integer' => ['amount', 'lots'],
    'an unparseable boolean' => ['active', 'maybe'],
    'malformed json' => ['meta', '{"tier": '],
    'a date nothing can read' => ['created_at', 'the day before yesterday'],
    'an empty value in a NOT NULL column' => ['amount', ''],
]);

it('writes JSON decoded when the model casts the column, so the row is not double-encoded', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    expect($this->browser(writableData())->update('admin-record', 1, ['meta' => '{"tier":"platinum"}'])->isDone())->toBeTrue();

    // Exactly the encoded object, with no escaped quotes: a double-encode would have stored
    // "{\"tier\":\"platinum\"}" and every later read would have decoded it to a string.
    expect(DB::table('admin_records')->where('id', 1)->value('meta'))->toBe('{"tier":"platinum"}');
});

it('turns an emptied nullable field into null rather than into an empty string', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    expect($this->browser(writableData())->update('admin-record', 1, ['created_at' => ''])->isDone())->toBeTrue()
        ->and(DB::table('admin_records')->where('id', 1)->value('created_at'))->toBeNull();
});

it('reports an update that changed nothing as done, with an empty change list', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $result = $this->browser(writableData())->update('admin-record', 1, ['email' => 'ada@example.test']);

    expect($result->outcome)->toBe(DataWriteOutcome::Done)
        ->and($result->reason)->toBe('Nothing changed.')
        ->and($result->changed)->toBe([]);
});

it('refuses to mutate a resource whose entities are not Eloquent models', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedNotes();

    $result = $this->browser(writableData())->update('plain-note', 1, ['title' => 'Renamed']);

    expect($result->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($result->reason)->toContain('not backed by an Eloquent model')
        ->and(DB::table('admin_notes')->where('id', 1)->value('title'))->toBe('Alpha');
});

// A numeric form field name arrives as an INTEGER array key. Before the key type was widened, the
// unknown-column filter took a `string` parameter and blew up under strict_types on exactly this input.
it('rejects a crafted numeric field name as data, not as a TypeError', function () {
    /** @var DataBrowserTestCase $this */
    $this->seedRecords();

    $result = $this->browser(writableData())->update('admin-record', 1, [0 => 'injected', 'email' => 'x@y.test']);

    expect($result->outcome)->toBe(DataWriteOutcome::Refused)
        ->and($result->reason)->toContain('are not columns of this resource')
        ->and(DB::table('admin_records')->where('id', 1)->value('email'))->toBe('ada@example.test');
});

// Not a formality: `create()` is refused on principle (a generic form cannot honour a constructor's
// invariants — see DataBrowser's class docblock), and this is the guard that keeps a future edit from
// quietly adding one back.
it('offers no create operation at all', function () {
    expect(get_class_methods(DataBrowser::class))
        ->not->toContain('create')
        ->not->toContain('insert')
        ->not->toContain('store');
});
