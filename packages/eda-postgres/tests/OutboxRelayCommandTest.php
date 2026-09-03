<?php

declare(strict_types=1);

use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\Outbox\RelayDownstream;
use Firefly\Eda\Postgres\Tests\Fixtures\SpyDownstreamPublisher;
use Firefly\Eda\Postgres\Tests\Support\OutboxRelayCommandTestCase;
use Illuminate\Support\Facades\DB;

uses(OutboxRelayCommandTestCase::class);

/**
 * The end-to-end proof that firefly:outbox:relay can now do its job. Before the fix the command had no reachable
 * success path at all under firefly.eda.provider=postgres: with the key unset it printed a no-op and exited 0, and
 * with the key set it resolved EventPublisher::class — the outbox WRITER — and exited 1 rather than loop rows back
 * into the table it was draining.
 */
it('forwards a committed PENDING row to the configured downstream and marks it PUBLISHED', function () {
    /** @var OutboxRelayCommandTestCase $this */
    $spy = new SpyDownstreamPublisher;
    $this->app()->instance(RelayDownstream::BINDING, $spy);
    config(['firefly.eda.postgres.relay.downstream_provider' => 'rabbitmq']);

    DB::table(OutboxSchema::TABLE)->insert([
        'destination' => 'orders', 'channel' => 'firefly_eda_events', 'event_type' => 'order.created',
        'payload' => '{"id":3}', 'headers' => '{}', 'status' => OutboxSchema::STATUS_PENDING, 'attempts' => 0,
    ]);

    $exit = $this->runRelay();

    expect($exit)->toBe(0)
        ->and($spy->published)->toHaveCount(1)
        ->and($spy->published[0]->eventType)->toBe('order.created')
        ->and($spy->published[0]->payload)->toBe(['id' => 3])
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PUBLISHED);
});

it('exits FAILURE with an actionable message when downstream_provider is unset', function () {
    /** @var OutboxRelayCommandTestCase $this */
    $exit = $this->runRelay();
    // Kernel::output() drains the buffered output, so it is read exactly once per command run.
    $output = $this->relayOutput();

    // Not exit 0: a relay with nothing to relay to is a misconfiguration, and the old exit-0 no-op is exactly the
    // silence this whole fix is about. The message must point at the in-process alternative.
    expect($exit)->toBe(1)
        ->and($output)->toContain('firefly.eda.postgres.relay.downstream_provider')
        ->and($output)->toContain('firefly:eda:consume');
});

it('exits FAILURE naming the missing package when the configured adapter is not installed', function () {
    /** @var OutboxRelayCommandTestCase $this */
    config(['firefly.eda.postgres.relay.downstream_provider' => 'nats']);

    expect($this->runRelay())->toBe(1)
        ->and($this->relayOutput())->toContain('nats');
});

it('leaves every row PENDING when the downstream cannot be resolved — nothing is acked', function () {
    /** @var OutboxRelayCommandTestCase $this */
    config(['firefly.eda.postgres.relay.downstream_provider' => stdClass::class]);

    DB::table(OutboxSchema::TABLE)->insert([
        'destination' => 'orders', 'channel' => 'firefly_eda_events', 'event_type' => 'order.created',
        'payload' => '{"id":4}', 'headers' => '{}', 'status' => OutboxSchema::STATUS_PENDING, 'attempts' => 0,
    ]);

    // stdClass is a real, constructible class, so the container hands one back happily — but it is not an
    // EventPublisher. The type guard fires BEFORE a single row is claimed, which is what keeps a misconfigured
    // relay from marking rows PUBLISHED without ever forwarding them.
    expect($this->runRelay())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PENDING)
        ->and(DB::table(OutboxSchema::TABLE)->value('attempts'))->toBe(0);
});
