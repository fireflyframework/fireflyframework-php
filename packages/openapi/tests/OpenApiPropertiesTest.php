<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\OpenApi\OpenApiProperties;
use Illuminate\Config\Repository;

/**
 * @param  array<string, mixed>  $firefly
 */
function openApiPropertiesFrom(array $firefly): OpenApiProperties
{
    return OpenApiProperties::fromConfig(new Config(new Repository(['firefly' => $firefly])));
}

it('defaults to an enabled spec and viewer with the CDN opt-in OFF', function () {
    $properties = openApiPropertiesFrom([]);

    expect($properties->enabled)->toBeTrue()
        ->and($properties->specPath)->toBe('openapi.json')
        ->and($properties->viewerEnabled)->toBeTrue()
        ->and($properties->viewerPath)->toBe('openapi')
        // The default viewer must never reach the network. Flipping this default is a supply-chain change,
        // not a cosmetic one — see ViewerPage.
        ->and($properties->viewerCdn)->toBeFalse()
        ->and($properties->servers)->toBe([])
        ->and($properties->excludePathPrefixes)->toBe([]);
});

it('strips the leading slash the Router strips anyway, so links and routes agree', function () {
    $properties = openApiPropertiesFrom(['openapi' => ['path' => '/docs/api.json', 'viewer' => ['path' => '/docs/']]]);

    expect($properties->specPath)->toBe('docs/api.json')
        ->and($properties->viewerPath)->toBe('docs');
});

it('falls back to the default path when the configured one trims to nothing', function () {
    $properties = openApiPropertiesFrom(['openapi' => ['path' => '/', 'viewer' => ['path' => '///']]]);

    expect($properties->specPath)->toBe('openapi.json')
        ->and($properties->viewerPath)->toBe('openapi');
});

it('accepts servers as bare URL strings or as OpenAPI server objects', function () {
    $properties = openApiPropertiesFrom(['openapi' => ['servers' => [
        'https://api.test',
        ['url' => 'https://staging.test', 'description' => 'staging'],
    ]]]);

    expect($properties->servers)->toBe([
        ['url' => 'https://api.test'],
        ['url' => 'https://staging.test', 'description' => 'staging'],
    ]);
});

it('drops a server entry with no url rather than emitting an invalid Server Object', function () {
    $properties = openApiPropertiesFrom(['openapi' => ['servers' => [
        ['description' => 'no url here'],
        '',
        42,
        ['url' => 'https://api.test'],
    ]]]);

    expect($properties->servers)->toBe([['url' => 'https://api.test']]);
});

it('reads the exclude list as a CSV of path prefixes', function () {
    $properties = openApiPropertiesFrom(['openapi' => ['exclude' => '/internal, /admin ,']]);

    expect($properties->excludePathPrefixes)->toBe(['/internal', '/admin']);
});
