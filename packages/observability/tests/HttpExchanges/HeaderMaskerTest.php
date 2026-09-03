<?php

declare(strict_types=1);

use Firefly\Observability\HttpExchanges\HeaderMasker;

/**
 * The defect these pin: EnvEndpoint's mask (`password|secret|token|key|credential|passwd`) is exhaustive for
 * CONFIG KEY names and has a hole big enough to drive the whole feature through when applied to HTTP HEADER
 * names — `Authorization`, the single most credential-bearing header on the web, matches none of those six
 * alternatives, and neither does `Cookie`. Reusing the config rule verbatim would have shipped a masker that
 * passes `Authorization: Bearer <token>` straight into a buffer that an operator then reads on a dashboard.
 */
it('masks the header-specific credential names the EnvEndpoint config rule cannot see', function () {
    $masked = HeaderMasker::mask([
        'Authorization' => ['Bearer super-secret-jwt'],
        'Cookie' => ['session=abc123'],
        'Proxy-Authorization' => ['Basic Zm9vOmJhcg=='],
    ]);

    expect($masked)->toBe([
        'authorization' => '******',
        'cookie' => '******',
        'proxy-authorization' => '******',
    ]);
});

it('keeps masking everything the EnvEndpoint rule already masked', function () {
    $masked = HeaderMasker::mask([
        'X-Api-Key' => ['k-123'],
        'X-Csrf-Token' => ['t-456'],
        'X-Client-Secret' => ['s-789'],
    ]);

    expect($masked)->toBe([
        'x-api-key' => '******',
        'x-client-secret' => '******',
        'x-csrf-token' => '******',
    ]);
});

it('lowercases names, folds multi-valued headers and preserves a present-but-empty header', function () {
    $masked = HeaderMasker::mask([
        'Accept' => ['application/json', 'text/html'],
        'X-Empty' => [null],
    ]);

    expect($masked)->toBe([
        'accept' => 'application/json, text/html',
        'x-empty' => '',
    ]);
});
