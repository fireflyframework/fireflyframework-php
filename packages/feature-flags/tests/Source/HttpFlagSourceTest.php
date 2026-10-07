<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Source\HttpFlagSource;
use Firefly\FeatureFlags\Tests\Support\FixedClock;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Psr\Log\NullLogger;

function featureFlagsHttpSource(Factory $http, string $token = 's3cret'): HttpFlagSource
{
    return new HttpFlagSource(FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => [
        'sources' => ['http' => ['enabled' => true, 'url' => 'https://flags.test/feature-flags/flagd.json', 'token' => $token, 'timeout' => '1s', 'refresh-interval' => '30s']],
    ]]]))), $http);
}

it('sends the bearer token and accepted revision, and keeps the accepted document on 304', function (): void {
    /** @var list<array{0: ?string, 1: ?string}> $seen */
    $seen = [];
    $http = new Factory;
    $http->fake(function (Request $request) use (&$seen) {
        $seen[] = [$request->header('Authorization')[0] ?? null, $request->header('If-None-Match')[0] ?? null];

        return ($request->header('If-None-Match')[0] ?? null) === '"v1"'
            ? Factory::response('', 304, ['ETag' => '"v1"'])
            : Factory::response('{"flags":{"a":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}}}', 200, ['ETag' => '"v1"']);
    });

    $source = featureFlagsHttpSource($http);
    $first = $source->load(null);

    expect($first?->revision)->toBe('"v1"')
        ->and($first?->document->keys())->toBe(['a'])
        ->and($source->load($first?->revision))->toBeNull()
        ->and($seen)->toBe([['Bearer s3cret', null], ['Bearer s3cret', '"v1"']])
        ->and($source->name())->toBe('http')
        ->and($source->precedence())->toBe(300)
        ->and($source->refreshInterval())->toBe(30.0)
        ->and($source->failsStartup())->toBeFalse()
        ->and($source->reportedRevision('"v1"'))->toBe('"v1"');
});

it('omits Authorization without a token and hashes the body when no ETag is sent', function (): void {
    $body = '{"flags":{}}';
    $http = new Factory;
    $http->fake(fn (Request $request) => Factory::response($request->hasHeader('Authorization') ? 'unexpected' : $body, 200));

    expect(featureFlagsHttpSource($http, '')->load(null)?->revision)->toBe('"'.hash('sha256', $body).'"');
});

it('reports connection failures, error statuses and malformed documents through their source errors', function (): void {
    $timeout = new Factory;
    $timeout->fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $unavailable = new Factory;
    $unavailable->fake(['*' => Factory::response('oops', 503)]);
    $garbage = new Factory;
    $garbage->fake(['*' => Factory::response('<html>', 200)]);
    $invalid = new Factory;
    $invalid->fake(['*' => Factory::response('{"flags":{"bad key":{"state":"ENABLED","variants":{"on":true}}}}', 200)]);

    expect(fn () => featureFlagsHttpSource($timeout)->load(null))->toThrow(FlagSourceUnavailable::class, 'timed out')
        ->and(fn () => featureFlagsHttpSource($unavailable)->load('"v1"'))->toThrow(FlagSourceUnavailable::class, 'HTTP 503')
        ->and(fn () => featureFlagsHttpSource($garbage)->load(null))->toThrow(InvalidFlagDefinition::class, 'not valid JSON')
        ->and(fn () => featureFlagsHttpSource($invalid)->load(null))->toThrow(InvalidFlagDefinition::class, 'invalid flag key');
});

it('does not accept an unsolicited 304 as the first snapshot', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('', 304)]);

    expect(fn () => featureFlagsHttpSource($http)->load(null))->toThrow(FlagSourceUnavailable::class, 'HTTP 304');
});

it('retries with the last accepted revision after refusing an invalid replacement', function (): void {
    /** @var list<?string> $seen */
    $seen = [];
    $http = new Factory;
    $http->fake(function (Request $request) use (&$seen) {
        $seen[] = $request->header('If-None-Match')[0] ?? null;

        return match (count($seen)) {
            1 => Factory::response('{"flags":{"a":{"state":"ENABLED","variants":{"on":true}}}}', 200, ['ETag' => '"v1"']),
            2 => Factory::response('{"flags":{"bad key":{}}}', 200, ['ETag' => '"v2"']),
            default => Factory::response('{"flags":{"a":{"state":"DISABLED","variants":{"on":true}}}}', 200, ['ETag' => '"v2"']),
        };
    });
    $source = featureFlagsHttpSource($http);
    $accepted = $source->load(null);

    expect(fn () => $source->load($accepted?->revision))->toThrow(InvalidFlagDefinition::class, 'invalid flag key');

    $recovered = $source->load($accepted?->revision);

    expect($recovered?->revision)->toBe('"v2"')
        ->and($recovered?->document->flag('a')?->isDisabled())->toBeTrue()
        ->and($seen)->toBe([null, '"v1"', '"v1"']);
});

it('keeps the registry last good document and accepted ETag through a refusal and later 304', function (): void {
    /** @var list<?string> $seen */
    $seen = [];
    $http = new Factory;
    $http->fake(function (Request $request) use (&$seen) {
        $seen[] = $request->header('If-None-Match')[0] ?? null;

        return match (count($seen)) {
            1 => Factory::response('{"flags":{"a":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}}}', 200, ['ETag' => '"v1"']),
            2 => Factory::response('{"flags":{"bad key":{}}}', 200, ['ETag' => '"v2"']),
            3 => Factory::response('{"flags":{"a":{"state":"DISABLED","variants":{"on":true},"defaultVariant":"on"}}}', 200, ['ETag' => '"v2"']),
            default => Factory::response('', 304, ['ETag' => '"v2"']),
        };
    });
    $clock = new FixedClock;
    $cache = new CacheRepository(new ArrayStore);
    $registry = new FlagRegistry([featureFlagsHttpSource($http)], new CacheBook($cache, new NullLogger), new RecordingApplicationEventPublisher, clock: $clock(...));

    expect($registry->document()->flag('a')?->isDisabled())->toBeFalse();

    $clock->now += 31.0;
    expect($registry->document()->flag('a')?->isDisabled())->toBeFalse()
        ->and($registry->states()[0]->status())->toBe('STALE')
        ->and($registry->states()[0]->revision)->toBe('"v1"');

    $clock->now += 31.0;
    expect($registry->document()->flag('a')?->isDisabled())->toBeTrue()
        ->and($registry->states()[0]->status())->toBe('UP')
        ->and($registry->states()[0]->revision)->toBe('"v2"');

    $clock->now += 31.0;
    expect($registry->document()->flag('a')?->isDisabled())->toBeTrue()
        ->and($seen)->toBe([null, '"v1"', '"v1"', '"v2"']);
});
