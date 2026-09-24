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
        //
        // READ OFF THE LAST CELL OF EACH ROW, which is the Trace column. It used to be read off every
        // `td[title]` in the body, and that was only ever a way of SAYING "the Trace column": since the
        // listing wave, the Path and Correlation cells clip and carry their own full value on `title`, the
        // way every clipped cell in the table vocabulary does. The claim here is unchanged — every trace
        // cell holds a 32-character hex id, and at least two rows have one — it is just aimed at the
        // column it was always about.
        ->assertSourceMissing('title=""')
        ->assertScript("Array.from(document.querySelectorAll('#http-body tr > td:last-child[title]')).every(function (td) { return /^[0-9a-f]{32}$/.test(td.getAttribute('title')); })", true)
        ->assertScript("document.querySelectorAll('#http-body tr > td:last-child[title]').length >= 2", true)
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

it('exposes the HTTP server timer as a histogram once buckets are configured', function (): void {
    /** @var TracedBrowserTestCase $this */
    visit('/greetings/Ada')->assertSourceHas('Hello, Ada!');

    visit('/actuator/prometheus')
        ->assertSourceHas('# TYPE http_server_requests_seconds histogram')
        ->assertSourceHas('http_server_requests_seconds_bucket{')
        ->assertSourceHas('le="+Inf"}')
        ->assertSourceHas('http_server_requests_seconds_count{')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'observability-prometheus');
});

it('lists the tracing switch as on among the observability switches', function (): void {
    /** @var TracedBrowserTestCase $this */
    $row = 'tr:has(input[name="key"][value="firefly.observability.tracing.enabled"])';

    visit('/firefly/settings')
        ->assertSee('Feature switches')
        ->assertSee('Observability')
        ->assertSee('firefly.observability.tracing.enabled')
        ->assertSeeIn($row.' span.bool', 'on')
        ->assertSeeIn($row.' button', 'Turn off')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'observability-settings');
});

it('writes an ECS document for a line logged inside a traced request, naming the trace the exchange row does', function (): void {
    /** @var TracedBrowserTestCase $this */
    visit('/browser-fixture/log')
        ->assertSee('One line was written to the application log.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'observability-log-fixture');

    $line = $this->lastFixtureLogLine();
    $traceId = $this->fixtureTraceId();

    // Pest's toHaveKey() tries the literal key first (`ecs.version`, `log.level` are literal ECS keys) and dot
    // notation second (`service.name`, `trace.id` are nested) — Arr::has()/Arr::get() semantics.
    expect($line)->toHaveKey('ecs.version', '8.11.0')
        ->toHaveKey('log.level', 'info')
        ->toHaveKey('message', TracedBrowserTestCase::LOG_MESSAGE)
        ->toHaveKey('service.name', 'LaraFly')
        ->toHaveKey('service.environment', 'local')
        ->toHaveKey('context.fixture', 'observability')
        ->toHaveKey('trace.id', $traceId)
        ->toHaveKey('span.id')
        ->toHaveKey('labels.correlation_id')
        ->toHaveKey('labels.request_id');

    // The same trace, seen from the dashboard and from the actuator.
    visit('/firefly/http')->assertSee('/browser-fixture/log')->assertSourceHas('title="'.$traceId.'"')->assertNoJavaScriptErrors();
    visit('/actuator/httpexchanges')->assertSourceHas('"traceId":"'.$traceId.'"')->assertNoJavaScriptErrors();
});
