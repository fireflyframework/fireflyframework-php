<?php

declare(strict_types=1);

use Firefly\OpenApi\Schema\SchemaRegistry;
use Firefly\OpenApi\Tests\Fixture\AddressPayload;
use Firefly\OpenApi\Tests\Fixture\Billing\Address as BillingAddress;
use Firefly\OpenApi\Tests\Fixture\CreateOrderRequest;
use Firefly\OpenApi\Tests\Fixture\DefaultsPayload;
use Firefly\OpenApi\Tests\Fixture\LegacyPayload;
use Firefly\OpenApi\Tests\Fixture\SelfReferential;
use Firefly\OpenApi\Tests\Fixture\Shipping\Address as ShippingAddress;
use Firefly\OpenApi\Tests\Support\FixtureDocument;

it('registers a DTO once and hands back the same $ref on every later use', function () {
    $registry = new SchemaRegistry;
    $factory = FixtureDocument::schemas();

    $first = $factory->ref(AddressPayload::class, $registry);
    $second = $factory->ref(AddressPayload::class, $registry);

    expect($first)->toBe('#/components/schemas/AddressPayload')
        ->and($second)->toBe($first)
        ->and(array_keys($registry->all()))->toBe(['AddressPayload']);
});

it('terminates on a self-referential DTO by referring back to the component being built', function () {
    $registry = new SchemaRegistry;

    $ref = FixtureDocument::schemas()->ref(SelfReferential::class, $registry);
    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = $registry->all();
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $schemas['SelfReferential']['properties'];

    expect($ref)->toBe('#/components/schemas/SelfReferential')
        ->and(array_keys($schemas))->toBe(['SelfReferential'])
        // A nullable nested DTO is a union, because a $ref cannot be widened by a sibling `type` in 2020-12.
        ->and($properties['parent'])->toBe([
            'anyOf' => [['$ref' => '#/components/schemas/SelfReferential'], ['type' => 'null']],
        ])
        ->and($schemas['SelfReferential']['required'])->toBe(['label']);
});

it('gives the second claimant of a short name its dotted fully-qualified name', function () {
    $registry = new SchemaRegistry;
    $factory = FixtureDocument::schemas();

    $first = $factory->ref(BillingAddress::class, $registry);
    $second = $factory->ref(ShippingAddress::class, $registry);

    expect($first)->toBe('#/components/schemas/Address')
        ->and($second)->toBe('#/components/schemas/'.str_replace('\\', '.', ShippingAddress::class))
        // Neither schema may be lost to the collision: the whole point is that both survive.
        ->and($registry->all())->toHaveCount(2);
});

it('lists constructor parameters in declaration order', function () {
    $registry = new SchemaRegistry;
    FixtureDocument::schemas()->ref(CreateOrderRequest::class, $registry);

    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = $registry->all();
    /** @var array<string, mixed> $properties */
    $properties = $schemas['CreateOrderRequest']['properties'];

    expect(array_keys($properties))->toBe([
        'reference', 'email', 'quantity', 'amount', 'currency', 'shipTo', 'coupon', 'idempotencyKey',
    ]);
});

it('documents a member the constructor does not take but the validator still enforces', function () {
    // ArgumentResolver hydrates only constructor parameters, but BeanValidator validates the RAW decoded
    // body, so a rule keyed to a plain (non-promoted) property is part of the request contract even though it
    // is never assigned. Dropping it would under-document what the server rejects.
    $registry = new SchemaRegistry;
    FixtureDocument::schemas()->ref(LegacyPayload::class, $registry);

    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = $registry->all();
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $schemas['LegacyPayload']['properties'];

    expect(array_keys($properties))->toBe(['name', 'legacyContact'])
        ->and($properties['legacyContact'])->toMatchArray(['format' => 'email'])
        ->and($schemas['LegacyPayload']['required'])->toBe(['name', 'legacyContact']);
});

it('sorts components by name so a regenerated document diffs cleanly', function () {
    $registry = new SchemaRegistry;
    $factory = FixtureDocument::schemas();

    $factory->ref(SelfReferential::class, $registry);
    $factory->ref(AddressPayload::class, $registry);

    expect(array_keys($registry->all()))->toBe(['AddressPayload', 'SelfReferential']);
});

it('publishes only the defaults a client could actually send back', function () {
    $registry = new SchemaRegistry;
    FixtureDocument::schemas()->ref(DefaultsPayload::class, $registry);

    /** @var array<string, array<string, mixed>> $schemas */
    $schemas = $registry->all();
    /** @var array<string, array<string, mixed>> $properties */
    $properties = $schemas['DefaultsPayload']['properties'];

    // A scalar default is a promise the client can rely on: omit the member and the server applies this.
    expect($properties['label']['default'])->toBe('draft')
        ->and($properties['retries']['default'])->toBe(3)
        // An enum case and an object have no JSON literal a client could send back, so emitting an
        // approximation ("EUR", {}) would state a `default` the server never actually applies. Both are
        // dropped instead — the member is still documented, just without the false promise.
        ->and($properties['currency'])->not->toHaveKey('default')
        ->and($properties['address'])->not->toHaveKey('default');

    // Every member has a default, so none of them can be omitted-and-fail: `required` is absent entirely
    // rather than emitted empty (an empty `required` is invalid against the 3.1 meta-schema).
    expect($schemas['DefaultsPayload'])->not->toHaveKey('required');
});
