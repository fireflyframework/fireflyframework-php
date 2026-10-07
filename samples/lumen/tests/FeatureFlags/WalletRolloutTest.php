<?php

declare(strict_types=1);

namespace Lumen\Tests\FeatureFlags;

use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Event\FeatureFlagEvaluated;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\Management\FlagManagement;
use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Telemetry\ExposureEventHook;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Firefly\Testing\FeatureFlags\FeatureFlagOverrides;
use Illuminate\Database\Capsule\Manager;
use LogicException;
use Lumen\Application\WalletRollout;
use Lumen\Application\WalletRolloutGate;
use Lumen\Tests\LumenTestCase;
use OpenFeature\isolated\OpenFeatureAPIFactory;

uses(LumenTestCase::class);

it('keeps the wallet view dark and selects the enabled variant', function (): void {
    /** @var LumenTestCase $this */
    $flags = $this->fireflyContext()->get(FeatureFlags::class);
    if (! $flags instanceof FeatureFlags) {
        throw new LogicException('The Lumen sample did not boot feature flags.');
    }
    $rollout = new WalletRollout($flags);

    expect($rollout->balanceLabel())->toBe('Balance')
        ->and($rollout->checkoutView())->toBe('wallet.legacy');

    /** @var FeatureFlagOverrides $overrides */
    $overrides = withFeatureFlags([
        'wallet-balance-v2' => true,
        'wallet-checkout-view' => [
            'state' => 'ENABLED',
            'variants' => ['legacy' => 'legacy', 'v2' => 'v2'],
            'defaultVariant' => 'v2',
        ],
    ]);
    expect($rollout->balanceLabel())->toBe('Available balance')
        ->and($rollout->checkoutView())->toBe('wallet.v2')
        ->and($flags->details('wallet-checkout-view', 'legacy')->variant)->toBe('v2');

    $overrides->clear();
    expect($rollout->balanceLabel())->toBe('Balance')
        ->and($rollout->checkoutView())->toBe('wallet.legacy');
})->group('lumen');

it('targets the pro cohort from the shared LaraFly and PyFly vectors', function (): void {
    /** @var LumenTestCase $this */
    $flags = $this->fireflyContext()->get(FeatureFlags::class);
    if (! $flags instanceof FeatureFlags) {
        throw new LogicException('The Lumen sample did not boot feature flags.');
    }
    $vectors = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/packages/feature-flags/tests/Conformance/firefly-vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($vectors) || ! isset($vectors['cases']) || ! is_array($vectors['cases'])) {
        throw new LogicException('The shared flag vectors have no cases.');
    }
    /** @var list<array{name: string, document: array{flags: array{pro-reports: array<string, mixed>}}, context: array<string, mixed>, targetingKey: string, expect: array{value: bool, variant: string, reason: string}}> $cases */
    $cases = array_values(array_filter($vectors['cases'], static fn (mixed $case): bool => is_array($case) && in_array($case['name'] ?? null, ['a pro plan is entitled', 'a free plan is not entitled'], true)));
    expect($cases)->toHaveCount(2);

    withFeatureFlags(['pro-reports' => $cases[0]['document']['flags']['pro-reports']]);
    foreach ($cases as $case) {
        $result = $flags->details('pro-reports', false, $case['context'], $case['targetingKey']);
        expect($result->value)->toBe($case['expect']['value'])
            ->and($result->variant)->toBe($case['expect']['variant'])
            ->and($result->reason)->toBe($case['expect']['reason']);
    }
})->group('lumen');

it('keeps a gated rollout route closed until the override opens it', function (): void {
    /** @var LumenTestCase $this */
    $this->get('/api/v1/wallet-rollout')->assertNotFound();

    $overrides = withFeatureFlags(['wallet-rollout-route' => true]);
    $this->get('/api/v1/wallet-rollout')->assertOk()->assertJson(['label' => 'Available balance']);
    $overrides->clear();

    $this->get('/api/v1/wallet-rollout')->assertNotFound();
})->group('lumen');

it('uses the declared method fallback while a wallet feature is dark', function (): void {
    /** @var LumenTestCase $this */
    $gate = $this->fireflyContext()->get(WalletRolloutGate::class);
    if (! $gate instanceof WalletRolloutGate) {
        throw new LogicException('The Lumen sample did not boot the rollout gate.');
    }
    expect($gate->label('Ada'))->toBe('Hello Ada');

    $overrides = withFeatureFlags(['wallet-premium-label' => true]);
    expect($gate->label('Ada'))->toBe('Welcome back, Ada');
    $overrides->clear();
    expect($gate->label('Ada'))->toBe('Hello Ada');
})->group('lumen');

it('keeps flag and audit writes inside the caller transaction and rejects stale versions', function (): void {
    $database = new Manager;
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $database->getConnection();
    FeatureFlagSchema::create($connection->getSchemaBuilder());
    $store = new DatabaseFlagStore($connection);
    $off = ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off'];
    $on = ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on'];

    $connection->beginTransaction();
    $store->put('wallet-balance-v2', $off, 'release', 0);
    expect($store->get('wallet-balance-v2')?->version)->toBe(1)
        ->and($store->history('wallet-balance-v2'))->toHaveCount(1);
    $connection->rollBack();
    expect($store->get('wallet-balance-v2'))->toBeNull()
        ->and($store->history('wallet-balance-v2'))->toBe([]);

    $connection->beginTransaction();
    $store->put('wallet-balance-v2', $off, 'release', 0);
    $connection->commit();
    expect(fn () => $store->put('wallet-balance-v2', $on, 'stale', 0))->toThrow(FlagStoreConflict::class)
        ->and($store->get('wallet-balance-v2')?->version)->toBe(1)
        ->and($store->history('wallet-balance-v2'))->toHaveCount(1);
    $store->put('wallet-balance-v2', $on, 'release', 1);
    expect($store->get('wallet-balance-v2')?->version)->toBe(2)
        ->and($store->history('wallet-balance-v2'))->toHaveCount(2);
})->group('lumen');

it('records ordinary exposure but suppresses it for a management preview', function (): void {
    /** @var LumenTestCase $this */
    $running = $this->fireflyContext()->get(FeatureFlags::class);
    if (! $running instanceof FeatureFlags) {
        throw new LogicException('The Lumen sample did not boot feature flags.');
    }
    $events = new RecordingApplicationEventPublisher;
    $flags = new FeatureFlags(
        $running->provider(),
        new EvaluationContextResolver,
        [new ExposureEventHook($events)],
        api: OpenFeatureAPIFactory::createAPI(),
    );

    expect($flags->details('wallet-balance-v2', false)->value)->toBeFalse()
        ->and($events->ofType(FeatureFlagEvaluated::class))->toHaveCount(1);
    $management = $this->fireflyContext()->get(FlagManagement::class);
    if (! $management instanceof FlagManagement) {
        throw new LogicException('The Lumen sample did not boot flag management.');
    }
    expect($management->evaluate('wallet-balance-v2')['value'])->toBeFalse()
        ->and($events->ofType(FeatureFlagEvaluated::class))->toHaveCount(1);
})->group('lumen');
