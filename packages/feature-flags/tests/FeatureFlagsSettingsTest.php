<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;

/** @param array<string, mixed> $section */
function featureFlagsSettings(array $section = []): FeatureFlagsSettings
{
    return FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => $section]])));
}

/**
 * The `firefly.feature-flags` section that sets the one leaf `$leaf` (dotted, relative to the section) to `$value`.
 *
 * @return array<string, mixed>
 */
function featureFlagsSection(string $leaf, mixed $value): array
{
    $section = [];
    Arr::set($section, $leaf, $value);

    /** @var array<string, mixed> $section */
    return $section;
}

/**
 * The four duration leaves, relative to `firefly.feature-flags`, with the default (in seconds) each falls back to.
 *
 * @return array<string, float>
 */
function featureFlagsDurations(): array
{
    return [
        'sources.file.refresh-interval' => 5.0,
        'sources.http.refresh-interval' => 30.0,
        'sources.http.timeout' => 2.0,
        'sources.store.refresh-interval' => 5.0,
    ];
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

/*
 * Every leaf reaches its own property. All the non-boolean leaves carry distinct non-default values, so two
 * swapped strings or durations fail; the eight booleans all default to false, so each case switches on a
 * different set of them and every property is checked against that set, so two swapped booleans fail too.
 */
it('maps every leaf to its own property', function (array $on): void {
    /** @var list<string> $on */
    $flag = static fn (string $leaf): bool => in_array($leaf, $on, true);

    $settings = featureFlagsSettings([
        'enabled' => $flag('enabled'),
        'flags' => ['beta-banner' => true],
        'evaluators' => ['is-staff' => ['in' => ['staff', ['var' => 'roles']]]],
        'sources' => [
            'file' => ['enabled' => $flag('sources.file.enabled'), 'path' => '/srv/flags/flagd.yaml', 'refresh-interval' => '7s'],
            'http' => [
                'enabled' => $flag('sources.http.enabled'),
                'url' => 'https://flags.example.test/feature-flags/flagd.json',
                'token' => 'http-token',
                'refresh-interval' => '45s',
                'timeout' => '3s',
            ],
            'store' => ['enabled' => $flag('sources.store.enabled'), 'driver' => 'memory', 'connection' => 'flags', 'refresh-interval' => '9s'],
        ],
        'context' => ['tenant-attribute' => 'organisation'],
        'web' => ['disabled-status' => 403],
        'events' => ['evaluations' => $flag('events.evaluations')],
        'management' => ['writes' => $flag('management.writes')],
        'server' => [
            'enabled' => $flag('server.enabled'),
            'path' => 'flags/served.json',
            'token' => 'server-token',
            'allow-anonymous' => $flag('server.allow-anonymous'),
        ],
    ]);

    expect($settings->enabled)->toBe($flag('enabled'))
        ->and($settings->flags)->toBe(['beta-banner' => true])
        ->and($settings->evaluators)->toBe(['is-staff' => ['in' => ['staff', ['var' => 'roles']]]])
        ->and($settings->file->enabled)->toBe($flag('sources.file.enabled'))
        ->and($settings->file->path)->toBe('/srv/flags/flagd.yaml')
        ->and($settings->file->refreshInterval)->toBe(7.0)
        ->and($settings->http->enabled)->toBe($flag('sources.http.enabled'))
        ->and($settings->http->url)->toBe('https://flags.example.test/feature-flags/flagd.json')
        ->and($settings->http->token)->toBe('http-token')
        ->and($settings->http->refreshInterval)->toBe(45.0)
        ->and($settings->http->timeout)->toBe(3.0)
        ->and($settings->store->enabled)->toBe($flag('sources.store.enabled'))
        ->and($settings->store->driver)->toBe('memory')
        ->and($settings->store->connection)->toBe('flags')
        ->and($settings->store->refreshInterval)->toBe(9.0)
        ->and($settings->tenantAttribute)->toBe('organisation')
        ->and($settings->disabledStatus)->toBe(403)
        ->and($settings->publishEvaluations)->toBe($flag('events.evaluations'))
        ->and($settings->writes)->toBe($flag('management.writes'))
        ->and($settings->server->enabled)->toBe($flag('server.enabled'))
        ->and($settings->server->path)->toBe('/flags/served.json')
        ->and($settings->server->token)->toBe('server-token')
        ->and($settings->server->allowAnonymous)->toBe($flag('server.allow-anonymous'));
})->with([
    'every boolean on' => [[
        'enabled', 'sources.file.enabled', 'sources.http.enabled', 'sources.store.enabled',
        'events.evaluations', 'management.writes', 'server.enabled', 'server.allow-anonymous',
    ]],
    'only enabled' => [['enabled']],
    'only sources.file.enabled' => [['sources.file.enabled']],
    'only sources.http.enabled' => [['sources.http.enabled']],
    'only sources.store.enabled' => [['sources.store.enabled']],
    'only events.evaluations' => [['events.evaluations']],
    'only management.writes' => [['management.writes']],
    'only server.enabled' => [['server.enabled']],
    'only server.allow-anonymous' => [['server.allow-anonymous']],
]);

it('accepts each allowed disabled-status, from config or from the environment', function (int|string $written, int $status): void {
    expect(featureFlagsSettings(['web' => ['disabled-status' => $written]])->disabledStatus)->toBe($status);
})->with([[404, 404], [403, 403], [503, 503], ['503', 503]]);

it('refuses a negative duration, written as a number or as text, naming its key', function (string $leaf, mixed $written): void {
    expect(fn () => featureFlagsSettings(featureFlagsSection($leaf, $written)))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.'.$leaf);
})->with(array_keys(featureFlagsDurations()))->with([-5, -0.5, '-5s', '-PT1M']);

it('refuses a duration that is not a finite number of seconds, naming its key', function (string $leaf, mixed $written): void {
    expect(fn () => featureFlagsSettings(featureFlagsSection($leaf, $written)))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.'.$leaf);
})->with(array_keys(featureFlagsDurations()))->with([INF, NAN, '', 'soon', true, [['5s']]]);

it('accepts zero as a file or store refresh interval: the source is re-checked on every refresh', function (string $leaf, mixed $written): void {
    $settings = featureFlagsSettings(featureFlagsSection($leaf, $written));
    $intervals = [
        'sources.file.refresh-interval' => $settings->file->refreshInterval,
        'sources.store.refresh-interval' => $settings->store->refreshInterval,
    ];

    expect($intervals[$leaf])->toBe(0.0);
})->with(['sources.file.refresh-interval', 'sources.store.refresh-interval'])->with([0, 0.0, '0s', 'PT0S']);

it('refuses an http refresh interval that is not above zero, naming its key', function (mixed $written): void {
    expect(fn () => featureFlagsSettings(['sources' => ['http' => ['refresh-interval' => $written]]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.http.refresh-interval');
})->with([0, 0.0, '0s', '0ms', 'PT0S']);

it('refuses an http timeout that is not above zero, naming its key', function (mixed $written): void {
    expect(fn () => featureFlagsSettings(['sources' => ['http' => ['timeout' => $written]]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.http.timeout');
})->with([0, 0.0, '0s', '0ms', 'PT0S']);

/*
 * DurationReader::get() is named get() so the key discovery in tests/ConfigReferenceTest.php (and the same pattern
 * in DocsCodeAudit::keyLiterals()) finds each duration key at its read site. Renaming the method would silently
 * drop four keys from the configuration-reference check; this pins it, with ConfigReferenceTest's pattern copied
 * verbatim.
 */
it('reads every duration where the configuration-reference check can see its key', function (): void {
    preg_match_all(
        "/->(?:bool|string|int|array|get|has)\(\s*'(firefly\.[a-z0-9._\-]+)'/",
        (string) file_get_contents(dirname(__DIR__).'/src/FeatureFlagsSettings.php'),
        $matches,
    );

    expect($matches[1])->toContain(...array_map(
        static fn (string $leaf): string => 'firefly.feature-flags.'.$leaf,
        array_keys(featureFlagsDurations()),
    ));
});

it('falls back to the default for a duration set to null, as an unset env() leaves it', function (string $leaf): void {
    $settings = featureFlagsSettings(featureFlagsSection($leaf, null));

    expect([
        'sources.file.refresh-interval' => $settings->file->refreshInterval,
        'sources.http.refresh-interval' => $settings->http->refreshInterval,
        'sources.http.timeout' => $settings->http->timeout,
        'sources.store.refresh-interval' => $settings->store->refreshInterval,
    ])->toBe(featureFlagsDurations());
})->with(array_keys(featureFlagsDurations()));

it('falls back to the default for every leaf set to null', function (): void {
    $nulls = [
        'enabled' => null,
        'flags' => null,
        'evaluators' => null,
        'sources' => [
            'file' => ['enabled' => null, 'path' => null, 'refresh-interval' => null],
            'http' => ['enabled' => null, 'url' => null, 'token' => null, 'refresh-interval' => null, 'timeout' => null],
            'store' => ['enabled' => null, 'driver' => null, 'connection' => null, 'refresh-interval' => null],
        ],
        'context' => ['tenant-attribute' => null],
        'web' => ['disabled-status' => null],
        'events' => ['evaluations' => null],
        'management' => ['writes' => null],
        'server' => ['enabled' => null, 'path' => null, 'token' => null, 'allow-anonymous' => null],
    ];

    expect(featureFlagsSettings($nulls))->toEqual(featureFlagsSettings());
});

it('refuses a store connection that is not a connection name, naming its key', function (mixed $connection): void {
    expect(fn () => featureFlagsSettings(['sources' => ['store' => ['connection' => $connection]]]))
        ->toThrow(ConfigurationException::class, 'firefly.feature-flags.sources.store.connection');
})->with([5, 1.5, true, false, [['flags']]]);
