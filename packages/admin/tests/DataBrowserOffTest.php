<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\DataBrowserOffTestCase;

uses(DataBrowserOffTestCase::class);

/**
 * The data browser reads the records behind an application's repositories, which is a far bigger disclosure
 * than beans or configuration — so it stays off even when the rest of the dashboard is on, and off means
 * every shape answers the same way rather than some paths 404ing while others render.
 */
it('is hidden from the menu', function () {
    /** @var DataBrowserOffTestCase $this */
    $response = $this->get('/firefly');

    $response->assertStatus(200);
    expect($response->getContent())->not->toContain('Browse data');
});

it('404s every read shape', function (string $path) {
    /** @var DataBrowserOffTestCase $this */
    $this->get($path)->assertStatus(404);
})->with([
    '/firefly/data',
    '/firefly/data?resource=order',
    '/firefly/data?resource=order&id=1',
]);

// A write must answer exactly as a read does. Redirecting instead would tell the caller the request was
// understood and merely declined, which is a different fact from "this does not exist".
it('404s a write rather than redirecting', function (string $op) {
    /** @var DataBrowserOffTestCase $this */
    $this->post('/firefly/data', ['resource' => 'order', 'id' => '1', 'op' => $op])->assertStatus(404);
})->with(['delete', 'update']);

it('says how to switch it on rather than pretending nothing is there', function () {
    /** @var DataBrowserOffTestCase $this */
    $this->get('/firefly/data')
        ->assertSee('firefly.admin.data.enabled', false)
        ->assertSee('firefly.admin.data.writable', false);
});
