<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Config\Config;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Management\FlagManagement;
use Firefly\FeatureFlags\Management\FlagsEndpoint;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use Firefly\FeatureFlags\Tests\Support\FixedProvider;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Firefly\FeatureFlags\Tests\Support\ManagementFixtures;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StubFlagSource;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

function featureFlagsEndpoint(?FlagManagement $management): FlagsEndpoint
{
    $illuminate = new Container;
    if ($management !== null) {
        $illuminate->instance(FlagManagement::class, $management);
    }

    return new FlagsEndpoint(new FireflyContainer($illuminate, new ComponentManifest([])));
}

/** @return array{0: int, 1: string, 2: string} status, content type, body */
function featureFlagsAnswer(?EndpointResponse $response): array
{
    expect($response)->not->toBeNull();
    /** @var EndpointResponse $response */
    expect($response->body)->toBeString();
    /** @var string $body */
    $body = $response->body;

    return [$response->status, $response->contentType, $body];
}

it('is the endpoint `flags`, enabled only with FlagManagement, and declines other shapes', function (): void {
    $endpoint = featureFlagsEndpoint((new ManagementFixtures(ManagementFixtures::flags()))->management);

    expect($endpoint->endpointId())->toBe('flags')
        ->and($endpoint->enabled())->toBeTrue()
        ->and(featureFlagsEndpoint(null)->enabled())->toBeFalse()
        ->and(featureFlagsEndpoint(null)->handle(new EndpointRequest('GET', [])))->toBeNull()
        ->and($endpoint->handle(new EndpointRequest('GET', ['a', 'b'])))->toBeNull()
        ->and($endpoint->handle(new EndpointRequest('POST', [])))->toBeNull()
        ->and($endpoint->handle(new EndpointRequest('DELETE', ['kill-switch'])))->toBeNull();
});

it('answers every refusal as {error, message} with its status', function (string $case, int $status): void {
    $flags = ManagementFixtures::flags();
    $request = match ($case) {
        'writes-disabled' => [(new ManagementFixtures($flags, writes: false))->management, 'kill-switch', ['action' => 'enable'], null],
        'not-writable' => [(new ManagementFixtures($flags, store: false))->management, 'kill-switch', ['action' => 'enable'], null],
        'invalid-definition' => [(new ManagementFixtures($flags))->management, 'x', [], '{"action":"put","definition":{"state":"ON"}}'],
        'unknown-flag' => [(new ManagementFixtures($flags))->management, 'nope', ['action' => 'evaluate'], null],
        'unknown-variant' => [(new ManagementFixtures($flags))->management, 'checkout-flow', ['action' => 'default-variant', 'variant' => 'v9'], null],
        'conflict' => [(new ManagementFixtures($flags))->management, 'kill-switch', ['action' => 'enable', 'expectedVersion' => 3], null],
        default => [(new ManagementFixtures($flags))->management, 'kill-switch', [], '{nope'],
    };
    [$management, $key, $body, $raw] = $request;

    [$answered, $type, $text] = featureFlagsAnswer(featureFlagsEndpoint($management)->handle(new EndpointRequest('POST', [$key], [], $body, $raw)));

    expect([$answered, $type])->toBe([$status, 'application/json'])
        ->and($text)->toStartWith('{"error":"'.$case.'","message":"');
})->with([
    ['writes-disabled', 403],
    ['not-writable', 409],
    ['invalid-definition', 422],
    ['unknown-flag', 404],
    ['unknown-variant', 422],
    ['conflict', 409],
    ['bad-request', 400],
]);

it('records `admin` for ?via=admin and `actuator` otherwise', function (): void {
    $fixtures = new ManagementFixtures(ManagementFixtures::flags());
    $endpoint = featureFlagsEndpoint($fixtures->management);

    $endpoint->handle(new EndpointRequest('POST', ['kill-switch'], ['via' => 'admin'], ['action' => 'enable']));
    $endpoint->handle(new EndpointRequest('POST', ['kill-switch'], ['via' => 'elsewhere'], ['action' => 'disable']));

    expect(array_column($fixtures->management->describe('kill-switch')['history'], 'actor'))->toBe(['actuator', 'admin']);
});

it('serves an external provider as read-only with an empty overview', function (): void {
    $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['enabled' => true, 'management' => ['writes' => true]]]])));
    $endpoint = featureFlagsEndpoint(new FlagManagement($settings, new FixedProvider));

    [$status, , $body] = featureFlagsAnswer($endpoint->handle(new EndpointRequest('GET', [])));

    expect($status)->toBe(200)
        ->and($body)->toContain('"provider":{"name":"fixed-vendor","status":"READY"}')
        ->and($body)->toContain('"writable":false')
        ->and($body)->toContain('"sources":[],"flags":[]');
});

it('passes an accepted write with deferred visibility through as refreshPending', function (): void {
    $store = new MemoryFlagStore(FlagStores::clock());
    $events = new RecordingApplicationEventPublisher;
    $registry = new FlagRegistry([new StubFlagSource('store', 400, 0.0)], new CacheBook(new Cache(new ArrayStore), new RecordingLogger), $events);
    $registry->start();
    $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['enabled' => true, 'management' => ['writes' => true]]]])));
    $management = new FlagManagement($settings, new FireflyFlagProvider($registry, new DefaultFlagdEvaluator), $registry, new FlagStoreWriter($store, $registry, $events), new EvaluationContextResolver);

    [$status, , $body] = featureFlagsAnswer(featureFlagsEndpoint($management)->handle(new EndpointRequest('POST', ['new'], rawBody: '{"action":"put","definition":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}}')));

    expect($status)->toBe(200)
        ->and($body)->toBe('{"key":"new","refreshPending":true}')
        ->and($store->get('new'))->not->toBeNull();
});
