<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

it('serves the overview as HTML', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Overview', false)
        ->assertSee('Backed off', false);
});

// The point of reading endpoints in-process: exposure is at its secure default of health,info here, so
// /actuator/beans would 404 — yet the dashboard renders beans anyway.
it('renders endpoints that are NOT exposed over HTTP', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->getJson('/actuator/beans')->assertStatus(404);

    $this->get('/firefly/beans')
        ->assertStatus(200)
        ->assertSee('Container', false);

    $this->get('/firefly/env')
        ->assertStatus(200)
        ->assertSee('Configuration', false);
});

it('serves every page in the menu', function (string $slug, string $marker) {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly/'.$slug)
        ->assertStatus(200)
        ->assertSee($marker, false);
})->with([
    ['beans', 'Beans'],
    ['conditions', 'Conditions'],
    ['mappings', 'Mappings'],
    ['scheduled', 'Scheduled tasks'],
    ['loggers', 'Loggers'],
    ['env', 'Environment'],
]);

it('404s an unknown page without leaking a stack trace', function () {
    /** @var AdminCapstoneTestCase $this */
    $response = $this->get('/firefly/not-a-page');

    $response->assertStatus(404)->assertSee('No such page', false);
    expect($response->getContent())->not->toContain('Stack trace');
});

it('shows the boot mode so nobody ships a reflection-scanning app by accident', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly')->assertSee('scanned', false);
});
