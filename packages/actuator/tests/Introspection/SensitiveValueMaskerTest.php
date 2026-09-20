<?php

declare(strict_types=1);

use Firefly\Actuator\Introspection\SensitiveValueMasker;

/**
 * The rule itself, tested once, where both /env and /configprops now get it from. Before it was extracted the
 * only coverage was an /env test that fed it two scalars — which is precisely why the array bypass survived.
 */
it('masks every spelling the rule names, case-insensitively and as a substring', function () {
    $masked = SensitiveValueMasker::mask([
        'password' => 'p', 'SECRET' => 's', 'apiToken' => 't', 'signing_key' => 'k',
        'credentials' => 'c', 'passwd' => 'w', 'host' => 'db.local', 'port' => 5432,
    ]);

    expect($masked)->toBe([
        'password' => '******', 'SECRET' => '******', 'apiToken' => '******', 'signing_key' => '******',
        'credentials' => '******', 'passwd' => '******', 'host' => 'db.local', 'port' => 5432,
    ]);
});

// The bug the audit found: the key decides FIRST, so a sensitive key masks its whole subtree instead of being
// descended into and having each leaf judged on its own harmless name.
it('masks a sensitive key that holds an array, without leaking its shape', function () {
    expect(SensitiveValueMasker::mask(['keys' => ['active' => 'A', 'previous' => 'B'], 'issuer' => 'auth']))
        ->toBe(['keys' => '******', 'issuer' => 'auth']);
});

it('recurses into a non-sensitive key and masks what it finds there', function () {
    expect(SensitiveValueMasker::mask(['datasource' => ['host' => 'db.local', 'password' => 'hunter2']]))
        ->toBe(['datasource' => ['host' => 'db.local', 'password' => '******']]);
});

// Integer keys can never match the pattern, so a list under a harmless key survives intact — the predicate is
// total over array-key rather than needing a separate "is this a list?" branch.
it('leaves a list under a harmless key alone', function () {
    expect(SensitiveValueMasker::mask(['hosts' => ['a.local', 'b.local']]))
        ->toBe(['hosts' => ['a.local', 'b.local']]);
});

it('masks a whole list held under a sensitive key', function () {
    expect(SensitiveValueMasker::mask(['tokens' => ['t1', 't2']]))->toBe(['tokens' => '******']);
});

it('leaves an empty tree empty', function () {
    expect(SensitiveValueMasker::mask([]))->toBe([]);
});

// The second audit finding: a key named `headers` is where an outbound client's credential lives —
// `firefly.observability.tracing.otlp.headers` documents `authorization=Bearer …` and `x-honeycomb-team=…` as
// its intended contents — and none of the six original words appear in that key, so /env rendered the vendor
// credential verbatim. `authorization` joins for the same reason: a key so named holds a credential by
// definition, whatever the value looks like.
it('masks a headers key, in any spelling, because a header bag is where a client credential lives', function () {
    $masked = SensitiveValueMasker::mask([
        'headers' => 'x-honeycomb-team=hcaik_SECRET,authorization=Bearer abc',
        'request_headers' => ['authorization' => 'Basic Zm9vOmJhcg=='],
        'defaultHeaders' => ['x-tenant' => 'acme'],
        'include-headers' => false,
        'endpoint' => 'https://api.honeycomb.io',
    ]);

    expect($masked)->toBe([
        'headers' => '******',
        'request_headers' => '******',
        'defaultHeaders' => '******',
        'include-headers' => '******',
        'endpoint' => 'https://api.honeycomb.io',
    ]);
});

it('masks an authorization key', function () {
    expect(SensitiveValueMasker::mask(['authorization' => 'Bearer x', 'proxy-authorization' => 'Basic y', 'issuer' => 'auth']))
        ->toBe(['authorization' => '******', 'proxy-authorization' => '******', 'issuer' => 'auth']);
});

// The cut is the PLURAL. The same rule names the data browser's sensitive columns, and `header_image`,
// `page_header`, `header_text` are what a CMS table calls its layout fields — masking them, excluding them
// from search and refusing them as update targets would cost real usability for no credential protected.
// A bag of headers is where a credential travels; a single `header` is a name or a piece of page furniture.
it('leaves a singular header key alone, so a page_header column is not treated as a credential', function () {
    expect(SensitiveValueMasker::mask(['header_image' => 'hero.png', 'page_header' => 'Welcome']))
        ->toBe(['header_image' => 'hero.png', 'page_header' => 'Welcome']);
});
