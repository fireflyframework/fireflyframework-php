<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DataBrowserTestCase;
use Illuminate\Support\Facades\DB;

uses(DataBrowserTestCase::class);

/**
 * The dashboard's half of the data browser: routing, the nav entry, and that the page renders what the
 * backend returns. The backend's own gates are proven in packages/admin/tests/Data/; these prove the UI
 * cannot reach past them.
 */
it('offers the data browser in the menu when it is switched on', function () {
    /** @var DataBrowserTestCase $this */
    $this->get('/firefly')->assertStatus(200)->assertSee('Browse data', false);
});

it('serves the resource index', function () {
    /** @var DataBrowserTestCase $this */
    $this->get('/firefly/data')->assertStatus(200)->assertSee('Resources', false);
});

// A slug nothing declared must not render a broken listing.
it('answers a listing for an unknown resource without leaking a stack trace', function () {
    /** @var DataBrowserTestCase $this */
    $response = $this->get('/firefly/data?resource=nope');

    $response->assertStatus(200);
    expect($response->getContent())->not->toContain('Stack trace');
});

it('404s a record on an unknown resource', function () {
    /** @var DataBrowserTestCase $this */
    $this->get('/firefly/data?resource=nope&id=1')->assertStatus(404);
});

/**
 * The outcome sentence of a write. AdminAction::redirect() flashes it into the session for the page it
 * redirects to, and the views print it — which held on paper only: the guard resolved `session` (the
 * MANAGER) and asked whether it was a Store, so it was false on every request and the operator saw a
 * silent redirect whether the write landed or was refused.
 */
it('flashes the outcome of a write onto the page it redirects to', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $this->post('/firefly/data', ['resource' => 'admin-record', 'id' => '1', 'op' => 'update', 'f' => ['email' => 'changed@example.test']])
        ->assertRedirect('/firefly/data?resource=admin-record&id=1')
        ->assertSessionHas('data-message', 'Updated 1 field(s).');

    $this->get('/firefly/data?resource=admin-record&id=1')
        ->assertStatus(200)
        ->assertSee('Updated 1 field(s).', false)
        ->assertSee('changed@example.test', false);
});

it('flashes a refusal too, so a write that did not land says so on the form', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $this->post('/firefly/data', ['resource' => 'admin-record', 'op' => 'create', 'f' => ['email' => 'x@example.test', 'amount' => 'lots']])
        ->assertRedirect('/firefly/data?resource=admin-record&new=1')
        ->assertSessionHas('data-message', 'The value for `amount` is not a valid int.');

    $this->get('/firefly/data?resource=admin-record&new=1')
        ->assertStatus(200)
        ->assertSee('The value for `amount` is not a valid int.', false);
});

/**
 * A NOT NULL column the database defaults is not required of the person filling the form: the form says
 * `optional` for it, and a blank submission leaves it to the schema. Before, the form said `required`
 * (nullability was the only thing it looked at) and the create was refused as "not a valid bool" — a row
 * that could not be created without typing a value the database was going to supply anyway.
 */
it('marks a defaulted column optional on the new-record form, and creates the row without it', function () {
    /** @var DataBrowserTestCase $this */
    $this->exposeAdminRecords();

    $form = $this->get('/firefly/data?resource=admin-record&new=1');
    $form->assertStatus(200);

    $html = (string) $form->getContent();
    expect($html)->toMatch('/name="f\[amount\]"[^>]*placeholder="required"/')
        ->toMatch('/name="f\[active\]"[^>]*placeholder="optional"/')
        ->toMatch('/name="f\[meta\]"[^>]*placeholder="optional"/');

    // Every field the form renders, as a browser submits them: the ones left alone arrive as ''.
    $this->post('/firefly/data', ['resource' => 'admin-record', 'op' => 'create', 'f' => [
        'email' => 'katherine@example.test', 'amount' => '7', 'active' => '', 'meta' => '', 'created_at' => '',
    ]])
        ->assertRedirect('/firefly/data?resource=admin-record&id=2')
        ->assertSessionHas('data-message', 'Created.');

    expect(DB::table('admin_records')->where(['id' => 2, 'email' => 'katherine@example.test', 'amount' => 7, 'active' => 1])->exists())->toBeTrue();
});
