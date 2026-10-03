<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Store\DatabaseFlagStore;
use Firefly\FeatureFlags\Tests\Support\FlagStores;

it('reads rows PyFly wrote', function (): void {
    $connection = FlagStores::sqlite();
    $connection->insert(
        'INSERT INTO firefly_feature_flags (flag_key, definition, version, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)',
        ['new-checkout', '{"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "on", "metadata": {"owner": "payments"}}', 3, '2026-10-01 12:00:00.123456', null],
    );
    $connection->insert(
        'INSERT INTO firefly_feature_flag_changes (flag_key, action, definition, previous, actor, changed_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['new-checkout', 'put', '{"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "on"}', null, 'cli:ana', '2026-10-01 11:59:59.000001'],
    );
    $connection->insert(
        'INSERT INTO firefly_feature_flag_changes (flag_key, action, definition, previous, actor, changed_at) VALUES (?, ?, ?, ?, ?, ?)',
        ['legacy', 'delete', null, '{"state": "DISABLED", "variants": {"x": {}}, "defaultVariant": "x"}', 'actuator', '2026-10-01 12:00:01'],
    );

    $store = new DatabaseFlagStore($connection);
    $flag = $store->get('new-checkout');
    $deleted = $store->history('legacy')[0];

    expect($flag?->version)->toBe(3)
        ->and($flag?->updatedBy)->toBeNull()
        ->and($flag?->updatedAt->format('Y-m-d\TH:i:s.u\Z'))->toBe('2026-10-01T12:00:00.123456Z')
        ->and(Json::encode($flag?->definition))->toBe('{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"on","metadata":{"owner":"payments"}}')
        ->and($store->revision())->toBe(2)
        ->and($store->history('new-checkout')[0]->toHistoryRow())->toBe(['id' => 1, 'action' => 'put', 'actor' => 'cli:ana', 'changedAt' => '2026-10-01T11:59:59Z'])
        ->and([$deleted->definition, Json::encode($deleted->previous)])->toBe([null, '{"state":"DISABLED","variants":{"x":{}},"defaultVariant":"x"}']);
});

it('writes rows PyFly can read: compact JSON objects, version from 1, UTC microseconds', function (): void {
    $connection = FlagStores::sqlite();
    (new DatabaseFlagStore($connection, FlagStores::clock()))->put('k', ['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on', 'metadata' => []], 'ada');

    $flag = (array) $connection->selectOne('SELECT flag_key, definition, version, updated_at, updated_by FROM firefly_feature_flags');
    $change = (array) $connection->selectOne('SELECT id, flag_key, action, definition, previous, actor, changed_at FROM firefly_feature_flag_changes');

    expect($flag)->toEqual(['flag_key' => 'k', 'definition' => '{"defaultVariant":"on","metadata":{},"state":"ENABLED","variants":{"on":true}}', 'version' => 1, 'updated_at' => '2026-10-01 12:00:00.000000', 'updated_by' => 'ada'])
        ->and($change)->toEqual(['id' => 1, 'flag_key' => 'k', 'action' => 'put', 'definition' => $flag['definition'], 'previous' => null, 'actor' => 'ada', 'changed_at' => '2026-10-01 12:00:00.000000']);
});
