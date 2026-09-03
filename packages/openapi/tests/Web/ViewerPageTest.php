<?php

declare(strict_types=1);

use Firefly\OpenApi\Web\ViewerPage;

it('renders a self-contained page that never reaches the network', function () {
    $html = new ViewerPage('Orders API')->render('/openapi.json', cdn: false);

    // The ONE network call the default viewer makes is to the spec route it was handed, so no element may
    // FETCH from another origin. Asserting on src/href rather than on the raw substring "http://" is the
    // honest version of that rule: the inline favicon is an SVG data URI, and an SVG carries the XML
    // namespace http://www.w3.org/2000/svg, which is an identifier a browser never requests. Banning the
    // substring would fail on a page that makes no request at all.
    preg_match_all('#\b(?:src|href)\s*=\s*["\']?(https?:)?//[^"\'\s>]+#i', $html, $external);

    expect($html)->toStartWith('<!DOCTYPE html>')
        ->and($html)->toContain('Orders API')
        ->and($external[0])->toBe([])
        ->and($html)->not->toContain('//cdn.')
        ->and(substr_count($html, '<script'))->toBe(1);
});

it('resolves $ref pointers client-side so a reader sees members, not pointers', function () {
    $html = new ViewerPage('Orders API')->render('/openapi.json', cdn: false);

    expect($html)->toContain('function deref')
        // The JSON Pointer walk itself: a local "#/a/b" pointer split and followed into the loaded document.
        ->and($html)->toContain("ref.slice(2).split('/')")
        // deref() is applied wherever a schema can be a pointer, so a reader never sees one.
        ->and($html)->toContain('typeof node.$ref')
        ->and($html)->toContain('deref(schema.properties[name])')
        ->and($html)->toContain('deref(content[type].schema)');
});

it('only reaches a CDN when the opt-in flag is explicitly turned on', function () {
    $off = new ViewerPage('Orders API')->render('/openapi.json', cdn: false);
    $on = new ViewerPage('Orders API')->render('/openapi.json', cdn: true);

    expect($off)->not->toContain('swagger-ui')
        ->and($on)->toContain('swagger-ui-bundle.js')
        // Pinned by exact version: an unpinned CDN reference is a remote-code-execution channel that
        // updates itself.
        ->and($on)->toMatch('#swagger-ui-dist@\d+\.\d+\.\d+/#');
});

it('escapes the configured spec path into the inline script', function () {
    // The path comes from application config, not from a request, so this is defence in depth — but a page
    // that renders a config value into inline script has no business relying on that distinction.
    $html = new ViewerPage('Orders API')->render('/openapi.json"</script><script>alert(1)</script>', cdn: false);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and(substr_count($html, '<script'))->toBe(1);
});

it('escapes the document title into the page markup', function () {
    $html = new ViewerPage('<img src=x onerror=alert(1)>')->render('/openapi.json', cdn: false);

    expect($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;img src=x');
});
