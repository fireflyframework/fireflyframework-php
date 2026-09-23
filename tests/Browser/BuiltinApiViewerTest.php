<?php

declare(strict_types=1);

use Firefly\Tests\Browser\Support\BuiltinViewerBrowserTestCase;

pest()->extend(BuiltinViewerBrowserTestCase::class);

/*
 | The built-in reference drew a response schema only when it was a plain object with `properties`, and named
 | every referenced component `object`. So the shapes a real document is made of came out blank or as `any`: a
 | nullable nested object (`anyOf` with null), a union return, a map, a list of components (`object[]`), and a
 | resource's `data` envelope. Each assertion reads the TYPE LABELS the page drew in one operation's success
 | schema, depth first — the text a reader actually sees beside each member.
 */

/** The type labels drawn in the success-schema panel of the selected operation, in document order. */
const VIEWER_SUCCESS_TYPES = <<<'JS'
    (() => {
        const panel = [...document.querySelectorAll('section')].find(s => /^2\d\d schema$/.test(s.querySelector('h2')?.textContent ?? ''));
        return panel ? [...panel.querySelectorAll('.type')].map(e => e.textContent).join(' ; ') : 'NO SCHEMA PANEL';
    })()
    JS;

it('names components and draws every arm of a nullable or union member', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="unionTagged"]')
        ->assertSee('200 schema')
        ->assertScript(VIEWER_SUCCESS_TYPES, 'string | integer ; Parcel | Label | null ; Parcel ; string ; integer ; Label ; string ; Parcel | null ; string ; integer')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-builtin-viewer-unions');
});

it('draws a union returned at the top level instead of nothing', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="unionEither"]')
        ->assertScript(VIEWER_SUCCESS_TYPES, 'Parcel ; string ; integer ; Label ; string')
        ->assertNoJavaScriptErrors();
});

it('draws a map response as its value type instead of nothing', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="arrayableCounters"]')
        ->assertSee('{ key }')
        ->assertScript(VIEWER_SUCCESS_TYPES, 'integer')
        ->assertNoJavaScriptErrors();
});

it('calls a list of components by the component\'s name', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="genericParcels"]')
        ->assertScript(VIEWER_SUCCESS_TYPES, 'Parcel[] ; Parcel ; string ; integer ; integer')
        ->assertNoJavaScriptErrors();
});

it('draws a resource inside its data envelope', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="resourceParcel"]')
        ->assertScript(VIEWER_SUCCESS_TYPES, 'ParcelResource ; string ; integer')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'openapi-builtin-viewer');
});

it('starts a union-typed request body from a value of the right type, not null', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="unionTag"]')
        ->assertScript("JSON.parse(document.getElementById('body').value).ref", '')
        ->assertScript("JSON.parse(document.getElementById('body').value).note", '')
        ->assertNoJavaScriptErrors();
});

it('shows the headers a response carries — a redirect is its Location', function (): void {
    visit('/viewer-fixture')
        ->click('[data-id="rawRedirect"]')
        // The header NAME, as its own label — the description already mentions the word in prose.
        ->assertScript("[...document.querySelectorAll('td span.mono')].map(e => e.textContent).join(',')", 'Location')
        ->assertNoJavaScriptErrors();
});
