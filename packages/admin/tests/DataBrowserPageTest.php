<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DataBrowserTestCase;

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
