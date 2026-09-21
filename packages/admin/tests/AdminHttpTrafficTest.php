<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\ObservabilityAdminTestCase;

uses(ObservabilityAdminTestCase::class);

it('lists a served request with its route template, its age and the trace id it was served under', function () {
    /** @var ObservabilityAdminTestCase $this */
    $this->withHeaders(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->get('/demo/7')
        ->assertStatus(200);

    $this->get('/firefly/http')
        ->assertStatus(200)
        ->assertSee('HTTP traffic', false)
        ->assertSee('/demo/{id}', false)
        ->assertSee('just now', false)
        ->assertSee('title="4bf92f3577b34da6a3ce929d0e0e4736"', false)
        ->assertSee('4bf92f35', false)
        ->assertDontSee('No exchanges recorded', false);
});

it('renders the empty state, not a broken table, when nothing was served', function () {
    /** @var ObservabilityAdminTestCase $this */
    $this->get('/firefly/http')->assertStatus(200)->assertSee('No exchanges recorded', false);
});
