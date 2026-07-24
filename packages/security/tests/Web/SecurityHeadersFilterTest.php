<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\Web\SecurityHeadersFilter;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @param  array<string, mixed>  $overrides
 */
function headersFilter(array $overrides = []): SecurityHeadersFilter
{
    return new SecurityHeadersFilter(new Config(new Repository(['firefly' => ['security' => ['headers' => ['enabled' => true] + $overrides]]])));
}

it('sets the default hardening headers on the response', function () {
    /** @var Response $response */
    $response = headersFilter()->handle(Request::create('/x', 'GET'), fn () => new Response('ok'));

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'self'");
});

it('honours config overrides', function () {
    /** @var Response $response */
    $response = headersFilter(['csp' => "default-src 'none'", 'frame_options' => 'SAMEORIGIN'])
        ->handle(Request::create('/x', 'GET'), fn () => new Response('ok'));

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});
