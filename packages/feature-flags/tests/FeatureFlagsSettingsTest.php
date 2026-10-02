<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $section */
function featureFlagsSettings(array $section = []): FeatureFlagsSettings
{
    return FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => $section]])));
}

it('defaults every key to the value in the spec table', function (): void {
    $settings = featureFlagsSettings();

    expect($settings->enabled)->toBeFalse()
        ->and($settings->flags)->toBe([])
        ->and($settings->evaluators)->toBe([])
        ->and($settings->file->enabled)->toBeFalse()
        ->and($settings->file->path)->toBe('')
        ->and($settings->file->refreshInterval)->toBe(5.0)
        ->and($settings->http->enabled)->toBeFalse()
        ->and($settings->http->url)->toBe('')
        ->and($settings->http->token)->toBe('')
        ->and($settings->http->refreshInterval)->toBe(30.0)
        ->and($settings->http->timeout)->toBe(2.0)
        ->and($settings->store->enabled)->toBeFalse()
        ->and($settings->store->driver)->toBe('database')
        ->and($settings->store->connection)->toBeNull()
        ->and($settings->store->refreshInterval)->toBe(5.0)
        ->and($settings->tenantAttribute)->toBe('tenant')
        ->and($settings->disabledStatus)->toBe(404)
        ->and($settings->publishEvaluations)->toBeFalse()
        ->and($settings->writes)->toBeFalse()
        ->and($settings->server->enabled)->toBeFalse()
        ->and($settings->server->path)->toBe('/feature-flags/flagd.json')
        ->and($settings->server->token)->toBe('')
        ->and($settings->server->allowAnonymous)->toBeFalse();
});

it('keeps inline flag and evaluator keys verbatim', function (): void {
    $settings = featureFlagsSettings([
        'flags' => ['new-checkout' => true, 'checkout.flow_v2' => 'v2'],
        'evaluators' => ['is-beta' => ['in' => ['beta', ['var' => 'roles']]]],
    ]);

    expect(array_keys($settings->flags))->toBe(['new-checkout', 'checkout.flow_v2'])
        ->and(array_keys($settings->evaluators))->toBe(['is-beta']);
});

it('reads durations in the framework grammar', function (mixed $written, float $seconds): void {
    expect(featureFlagsSettings(['sources' => ['store' => ['refresh-interval' => $written]]])->store->refreshInterval)->toBe($seconds);
})->with([
    ['250ms', 0.25],
    ['2s', 2.0],
    ['PT1M', 60.0],
    [10, 10.0],
    [0.5, 0.5],
]);

it('names the key when a duration does not parse', function (): void {
    expect(fn () => featureFlagsSettings(['sources' => ['http' => ['timeout' => 'soon']]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.http.timeout');
});

it('treats an empty or missing store connection as the default connection', function (mixed $connection, ?string $expected): void {
    expect(featureFlagsSettings(['sources' => ['store' => ['connection' => $connection]]])->store->connection)->toBe($expected);
})->with([[null, null], ['', null], ['flags', 'flags']]);

it('refuses a disabled-status other than 404, 403 or 503', function (): void {
    expect(fn () => featureFlagsSettings(['web' => ['disabled-status' => 500]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.web.disabled-status');
});

it('refuses an unknown store driver', function (): void {
    expect(fn () => featureFlagsSettings(['sources' => ['store' => ['driver' => 'mongo']]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.store.driver');
});

it('refuses an enabled file source without a path and an enabled http source without a url', function (): void {
    expect(fn () => featureFlagsSettings(['sources' => ['file' => ['enabled' => true]]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.file.path')
        ->and(fn () => featureFlagsSettings(['sources' => ['http' => ['enabled' => true]]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.http.url');
});

it('refuses an enabled sync server without a token unless anonymous access is explicit', function (): void {
    expect(fn () => featureFlagsSettings(['server' => ['enabled' => true]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.server.token')
        ->and(featureFlagsSettings(['server' => ['enabled' => true, 'allow-anonymous' => true]])->server->allowAnonymous)->toBeTrue()
        ->and(featureFlagsSettings(['server' => ['enabled' => true, 'token' => 's3cret']])->server->token)->toBe('s3cret');
});

it('normalises the sync path to one leading slash', function (): void {
    expect(featureFlagsSettings(['server' => ['path' => 'flags/doc.json', 'allow-anonymous' => true]])->server->path)->toBe('/flags/doc.json');
});

it('refuses an enabled sync server mounted on the root path', function (string $path): void {
    expect(fn () => featureFlagsSettings(['server' => ['enabled' => true, 'path' => $path, 'allow-anonymous' => true]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.server.path');
})->with(['', '/', '///']);
