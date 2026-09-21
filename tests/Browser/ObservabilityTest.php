<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\TracedBrowserTestCase;

pest()->extend(TracedBrowserTestCase::class);

/**
 * The observability wave as a browser sees it — and, for the one thing a browser cannot see, as the process
 * writes it: the trace id on the admin HTTP traffic page and in /actuator/httpexchanges, the HTTP server timer
 * as a Prometheus histogram, the tracing switch on the settings page, and the ECS log document a traced
 * request writes, carrying the same trace id the exchange row does. Each scenario makes its own page visits
 * first: every test boots a fresh application, and with it an empty exchange ring and empty meters.
 */
it('lists the visited pages on the HTTP traffic page, each with the trace id it ran under', function (): void {
    /** @var TracedBrowserTestCase $this */
    [$ada] = $this->seedOrders();

    visit('/greetings/Ada')->assertSourceHas('Hello, Ada!');
    visit('/orders/'.$ada)->assertSourceHas('Ada Lovelace');

    visit('/firefly/http')
        ->assertSee('HTTP traffic')
        ->assertSee('/greetings/{name}')
        ->assertSee('/orders/{id}')
        ->assertDontSee('No exchanges recorded')
        // The Trace column carries the id in `title`; a row recorded without one renders `title=""`.
        ->assertSourceMissing('title=""')
        ->assertScript("Array.from(document.querySelectorAll('#http-body td[title]')).every(function (td) { return /^[0-9a-f]{32}$/.test(td.getAttribute('title')); })", true)
        ->assertScript("document.querySelectorAll('#http-body td[title]').length >= 2", true)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'observability-http-traffic');
});

it('serves the exchanges as JSON with a traceId on each row', function (): void {
    /** @var TracedBrowserTestCase $this */
    visit('/greetings/Ada')->assertSourceHas('Hello, Ada!');

    visit('/actuator/httpexchanges')
        ->assertSourceHas('"recording":true')
        ->assertSourceHas('"traceId":"')
        ->assertSourceHas('greetings')
        ->assertSourceMissing('<h1')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'observability-httpexchanges-json');
});
