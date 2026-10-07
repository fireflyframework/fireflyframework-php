<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\HttpFlagSource;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\SyncServerTestCase;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory;

uses(SyncServerTestCase::class);

it('serves the composed document with an ETag and no-cache, and 304 for a matching If-None-Match', function (): void {
    /** @var SyncServerTestCase $this */
    $first = $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret']);
    $etag = (string) $first->headers->get('ETag');
    $again = $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret', 'If-None-Match' => $etag]);

    $first->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', 'no-cache, private');
    expect($etag)->toBe('"'.hash('sha256', (string) $first->getContent()).'"')
        ->and(Json::members(Json::decode((string) $first->getContent()))['flags'] ?? null)->toHaveKey('kill-switch');
    $again->assertStatus(304);
});

it('authenticates a conditional request before returning 304', function (): void {
    /** @var SyncServerTestCase $this */
    $etag = (string) $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret'])->headers->get('ETag');

    $this->get('/feature-flags/flagd.json', ['If-None-Match' => $etag])->assertStatus(401);
    $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer wrong', 'If-None-Match' => $etag])->assertStatus(401);
});

it('answers 401 without the bearer token or with a wrong one', function (string $header): void {
    /** @var SyncServerTestCase $this */
    $this->get('/feature-flags/flagd.json', $header === '' ? [] : ['Authorization' => $header])
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer realm="feature-flags"');
})->with([[''], ['Bearer nope'], ['Basic czNjcmV0']]);

it('serves empty objects and omits the test override layer', function (): void {
    /** @var SyncServerTestCase $this */
    /** @var FlagStoreWriter $writer */
    $writer = $this->fireflyContext()->get(FlagStoreWriter::class);
    $writer->put('banner', Json::decode('{"state":"ENABLED","variants":{"none":{},"promo":{"t":"x"}},"defaultVariant":"none","metadata":{}}'), 'ops');
    /** @var FlagRegistry $registry */
    $registry = $this->fireflyContext()->get(FlagRegistry::class);
    $registry->overrideForTests(FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['kill-switch' => false, 'test-only' => true]))]));

    $body = (string) $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret'])->getContent();
    $flags = Json::members(Json::members(Json::decode($body))['flags'] ?? null);

    expect($body)->toContain('"none":{}')
        ->and($body)->toContain('"$evaluators":{}')
        ->and(Json::members($flags['banner'] ?? null)['variants'] ?? null)->toEqual(['none' => new stdClass, 'promo' => ['t' => 'x']])
        ->and($flags)->not->toHaveKey('test-only')
        ->and(Json::members($flags['kill-switch'] ?? null)['defaultVariant'] ?? null)->toBe('on');
});

it('feeds an http source in another application', function (): void {
    /** @var SyncServerTestCase $this */
    /** @var FlagStoreWriter $writer */
    $writer = $this->fireflyContext()->get(FlagStoreWriter::class);
    $writer->put('checkout-flow', ['state' => 'ENABLED', 'variants' => ['control' => 'v1', 'treatment' => 'v2'], 'defaultVariant' => 'control', 'targeting' => ['fractional' => [['control', 50], ['treatment', 50]]]], 'ops');
    $served = $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret']);
    $served->assertOk();

    $http = new Factory;
    $http->fake(['*' => Factory::response((string) $served->getContent(), 200, ['ETag' => (string) $served->headers->get('ETag')])]);
    $settings = FeatureFlagsSettings::fromConfig(new Config(new ConfigRepository(['firefly' => ['feature-flags' => ['sources' => ['http' => ['enabled' => true, 'url' => 'https://orders.test/feature-flags/flagd.json', 'token' => 's3cret']]]]])));
    $client = new FlagRegistry(
        [new HttpFlagSource($settings, $http)],
        new CacheBook(new Repository(new ArrayStore), new RecordingLogger),
        new RecordingApplicationEventPublisher,
    );

    $resolution = (new DefaultFlagdEvaluator)->evaluate($client->document(), 'checkout-flow', FlagType::String, 'fallback', 'alice@example.com');

    expect($client->composition()->flag('checkout-flow')?->origin)->toBe('http')
        ->and($client->composition()->flag('kill-switch')?->origin)->toBe('http')
        ->and($resolution->reason->value)->toBe('TARGETING_MATCH')
        ->and(in_array($resolution->value, ['v1', 'v2'], true))->toBeTrue();
});

it('preserves leading NUL object members across store sync and HTTP source adoption', function (): void {
    /** @var SyncServerTestCase $this */
    /** @var FlagStoreWriter $writer */
    $writer = $this->fireflyContext()->get(FlagStoreWriter::class);
    $definition = Json::decode('{"state":"ENABLED","variants":{"v":{"\\u0000nested":{"0":{},"list":[],"float":1.0}}},"defaultVariant":"v","metadata":{"\\u0000owner":"ops"}}');
    $writer->put('portable', $definition, 'ops');
    $served = $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret'])->assertOk();
    $http = new Factory;
    $http->fake(['*' => Factory::response((string) $served->getContent(), 200)]);
    $settings = FeatureFlagsSettings::fromConfig(new Config(new ConfigRepository(['firefly' => ['feature-flags' => ['sources' => ['http' => ['enabled' => true, 'url' => 'https://flags.test/sync']]]]])));
    $client = new FlagRegistry([new HttpFlagSource($settings, $http)], new CacheBook(new Repository(new ArrayStore), new RecordingLogger), new RecordingApplicationEventPublisher);
    expect(Json::canonical($client->document()->flag('portable')?->toJsonValue()))->toBe(Json::canonical($definition))
        ->and($client->states()[0]->status())->toBe('UP');
});
