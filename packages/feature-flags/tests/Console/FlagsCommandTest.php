<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Management\FlagManagement;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\CacheBook;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use Firefly\FeatureFlags\Store\MemoryFlagStore;
use Firefly\FeatureFlags\Tests\Support\FlagsCommandTestCase;
use Firefly\FeatureFlags\Tests\Support\FlagStores;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StubFlagSource;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Artisan;

uses(FlagsCommandTestCase::class);

/**
 * @param  array<array-key, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function featureFlagsCli(array $arguments): array
{
    $exit = Artisan::call('firefly:flags', $arguments);

    return [$exit, Artisan::output()];
}

it('lists flags in a table and returns the management overview as JSON', function (): void {
    [$exit, $table] = featureFlagsCli(['action' => 'list']);
    [$jsonExit, $json] = featureFlagsCli(['action' => 'list', '--json' => true]);

    /** @var array{flags: list<array{key: string}>} $decoded */
    $decoded = Json::toPhp(Json::decode(trim($json)));

    expect([$exit, $jsonExit])->toBe([0, 0])
        ->and($table)->toContain('checkout-flow')->toContain('legacy-export')->toContain('yes')
        ->and(array_column($decoded['flags'], 'key'))->toBe(['checkout-flow', 'kill-switch', 'legacy-export', 'user-audience']);
});

it('shows a flag and evaluates explicit context with targeting-key precedence', function (): void {
    [$shownExit, $shown] = featureFlagsCli(['action' => 'show', 'key' => 'checkout-flow', '--json' => true]);
    [$evalExit, $evaluated] = featureFlagsCli(['action' => 'evaluate', 'key' => 'checkout-flow', '--context' => '{"plan":"pro","targetingKey":"from-context"}', '--targeting-key' => 'from-option', '--json' => true]);
    [$contextExit, $contextOutput] = featureFlagsCli(['action' => 'evaluate', 'key' => 'user-audience', '--context' => '{"targetingKey":"from-context"}', '--json' => true]);
    [$optionExit, $optionOutput] = featureFlagsCli(['action' => 'evaluate', 'key' => 'user-audience', '--context' => '{"targetingKey":"from-context"}', '--targeting-key' => 'from-option', '--json' => true]);

    /** @var array{key: string, origin: string} $detail */
    $detail = Json::toPhp(Json::decode(trim($shown)));
    /** @var array{value: string, reason: string} $preview */
    $preview = Json::toPhp(Json::decode(trim($evaluated)));

    expect([$shownExit, $evalExit, $contextExit, $optionExit])->toBe([0, 0, 0, 0])
        ->and($detail)->toMatchArray(['key' => 'checkout-flow', 'origin' => 'config'])
        ->and($preview)->toMatchArray(['value' => 'v2', 'reason' => 'TARGETING_MATCH'])
        ->and($contextOutput)->toContain('"value":"context"')
        ->and($optionOutput)->toContain('"value":"option"');
});

it('writes every action through the store and preserves JSON objects and floats', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'flag');
    expect($file)->toBeString();
    file_put_contents($file, '{"state":"ENABLED","variants":{"none":{},"promo":{"price":1.0}},"defaultVariant":"none"}');

    try {
        [$put] = featureFlagsCli(['action' => 'put', 'key' => 'banner', '--file' => $file, '--expected-version' => '0']);
        [$default] = featureFlagsCli(['action' => 'default-variant', 'key' => 'banner', 'value' => 'promo', '--expected-version' => '1']);
        [$disabled] = featureFlagsCli(['action' => 'disable', 'key' => 'banner', '--expected-version' => '2']);
        [$enabled] = featureFlagsCli(['action' => 'enable', 'key' => 'banner', '--expected-version' => '3']);
        [$show, $shown] = featureFlagsCli(['action' => 'show', 'key' => 'banner', '--json' => true]);
        [$deleted, $deletedOutput] = featureFlagsCli(['action' => 'delete', 'key' => 'banner', '--expected-version' => '4']);
    } finally {
        unlink($file);
    }

    expect([$put, $default, $disabled, $enabled, $show, $deleted])->toBe([0, 0, 0, 0, 0, 0])
        ->and($shown)->toContain('"none":{}')->toContain('"price":1.0')->toContain('"defaultVariant":"promo"')->toContain('"actor":"cli:')
        ->and($deletedOutput)->toContain('Deleted [banner].');
});

it('reports an accepted write with pending local visibility without claiming it is saved', function (): void {
    /** @var FlagsCommandTestCase $this */
    $store = new MemoryFlagStore(FlagStores::clock());
    $events = new RecordingApplicationEventPublisher;
    $registry = new FlagRegistry([new StubFlagSource('store', 400, 0.0)], new CacheBook(new Cache(new ArrayStore), new RecordingLogger), $events);
    $registry->start();
    $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['enabled' => true, 'management' => ['writes' => true]]]])));
    $management = new FlagManagement($settings, new FireflyFlagProvider($registry, new DefaultFlagdEvaluator), $registry, new FlagStoreWriter($store, $registry, $events), new EvaluationContextResolver);
    $this->app()->instance(FlagManagement::class, $management);

    $file = tempnam(sys_get_temp_dir(), 'flag');
    expect($file)->toBeString();
    file_put_contents($file, '{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}');

    try {
        [$exit, $human] = featureFlagsCli(['action' => 'put', 'key' => 'new-human', '--file' => $file]);
        [$jsonExit, $json] = featureFlagsCli(['action' => 'put', 'key' => 'new-json', '--file' => $file, '--json' => true]);
    } finally {
        unlink($file);
    }

    expect([$exit, $jsonExit])->toBe([0, 0])
        ->and($human)->toContain('Write accepted')->toContain('Check again')->not->toContain('Saved')
        ->and(trim($json))->toBe('{"key":"new-json","refreshPending":true}');
});

it('refuses unreadable or malformed definition files without a write', function (string $contents, string $message): void {
    $file = tempnam(sys_get_temp_dir(), 'flag');
    expect($file)->toBeString();
    file_put_contents($file, $contents);

    try {
        [$exit, $output] = featureFlagsCli(['action' => 'put', 'key' => 'bad-file', '--file' => $file, '--json' => true]);
        [$showExit, $showOutput] = featureFlagsCli(['action' => 'show', 'key' => 'bad-file', '--json' => true]);
    } finally {
        unlink($file);
    }

    expect([$exit, $showExit])->toBe([1, 1])
        ->and($output)->toContain('"error":"bad-request"')->toContain($message)
        ->and($showOutput)->toContain('"error":"unknown-flag"');
})->with([
    'invalid JSON' => ['{nope', 'not valid JSON'],
    'invalid UTF-8' => ["{\"state\":\"\xFF\"}", 'not valid JSON'],
    'array root' => ['[]', 'must contain a JSON object'],
]);

it('returns portable JSON errors and failure status for management refusals', function (array $arguments, string $error): void {
    [$exit, $output] = featureFlagsCli([...$arguments, '--json' => true]);

    /** @var array{error: string, message: string} $decoded */
    $decoded = Json::toPhp(Json::decode(trim($output)));

    expect($exit)->toBe(1)->and($decoded['error'])->toBe($error)->and($decoded['message'])->not->toBe('');
})->with([
    'conflict' => [['action' => 'enable', 'key' => 'kill-switch', '--expected-version' => '5'], 'conflict'],
    'unknown flag' => [['action' => 'show', 'key' => 'nope'], 'unknown-flag'],
    'missing key' => [['action' => 'enable'], 'bad-request'],
    'unknown action' => [['action' => 'rename', 'key' => 'x'], 'bad-request'],
    'bad context' => [['action' => 'evaluate', 'key' => 'kill-switch', '--context' => '{nope'], 'bad-request'],
    'empty context' => [['action' => 'evaluate', 'key' => 'kill-switch', '--context' => ''], 'bad-request'],
    'array context' => [['action' => 'evaluate', 'key' => 'kill-switch', '--context' => '[]'], 'bad-request'],
    'bad version' => [['action' => 'enable', 'key' => 'kill-switch', '--expected-version' => 'two'], 'bad-request'],
    'empty version' => [['action' => 'enable', 'key' => 'kill-switch', '--expected-version' => ''], 'bad-request'],
    'overflow version' => [['action' => 'enable', 'key' => 'kill-switch', '--expected-version' => '9223372036854775808'], 'bad-request'],
    'missing file' => [['action' => 'put', 'key' => 'x', '--file' => '/nonexistent/flag.json'], 'bad-request'],
]);
