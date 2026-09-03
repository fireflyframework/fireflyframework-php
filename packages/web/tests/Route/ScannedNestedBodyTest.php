<?php

declare(strict_types=1);

use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\AddressPayload;
use Firefly\Web\Tests\Fixtures\GeoPoint;
use Firefly\Web\Tests\Fixtures\MoneyTransferRequest;
use Firefly\Web\Tests\Fixtures\NodeRequest;
use Firefly\Web\Tests\Fixtures\TransferLine;
use Firefly\Web\Tests\Support\UncachedBootTestCase;

/**
 * ArgumentResolver gained a nested-hydration engine that reads a `dtos` shape table off the body binding —
 * but RouteScanner never emitted that key, so every production route fell through to the "plan cannot say"
 * path. The engine worked and was unreachable: a nested request body degraded to a clean 400 instead of
 * being hydrated.
 *
 * These pin the scanner half. The first two assert the compiled table directly; the last drives a real
 * request end to end, which is what actually proves the two halves agree.
 */
/** @return array<string, array<string, array{class: string|null, list: bool}>> */
function shapeTableFor(string $path): array
{
    $routes = (new RouteScanner)->scan(['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);

    foreach ($routes as $route) {
        if ($route->path !== $path) {
            continue;
        }
        foreach ($route->bindings as $binding) {
            if ($binding['kind'] !== 'body') {
                continue;
            }

            /** @var array<string, array<string, array{class: string|null, list: bool}>> $dtos */
            $dtos = $binding['dtos'] ?? [];

            return $dtos;
        }
    }

    return [];
}

it('compiles a row for every class reachable from the body DTO', function () {
    $dtos = shapeTableFor('/transfers');

    expect(array_keys($dtos))->toEqualCanonicalizing([
        MoneyTransferRequest::class,
        AddressPayload::class,
        GeoPoint::class,
        TransferLine::class,
    ]);

    // Three levels deep: the root's nested DTO has a nested DTO of its own.
    expect($dtos[AddressPayload::class]['geo'])->toBe(['class' => GeoPoint::class, 'list' => false]);
    expect($dtos[GeoPoint::class])->toHaveKeys(['lat', 'lon']);
});

// PHP's `array` type carries no element type, so list-ness can only come from the docblock —
// `@param list<TransferLine> $lines` on MoneyTransferRequest's constructor.
it('reads the element class of a list out of the constructor docblock', function () {
    $dtos = shapeTableFor('/transfers');

    $root = $dtos[MoneyTransferRequest::class];

    expect($root['lines'])->toBe(['class' => TransferLine::class, 'list' => true])
        ->and($root['beneficiary'])->toBe(['class' => AddressPayload::class, 'list' => false])
        ->and($root['amount'])->toBe(['class' => null, 'list' => false]);
});

// Keying by class is what makes depth unbounded. A DTO that points at itself is ONE row, so the walk
// terminates while the payload may still nest as deep as it likes.
it('emits a single row for a self-referential DTO instead of recursing forever', function () {
    $dtos = shapeTableFor('/nodes');

    expect($dtos)->toHaveKey(NodeRequest::class);
    expect($dtos[NodeRequest::class]['child'])->toBe(['class' => NodeRequest::class, 'list' => false]);
});

// A flat DTO compiles to a table with no nested class in it, so nothing about the old plan shape changes
// for the routes that never needed one.
it('says "nothing nested here" for a flat DTO', function () {
    $dtos = shapeTableFor('/accounts');

    expect($dtos)->toHaveCount(1);

    $shape = reset($dtos);
    expect($shape)->not->toBeFalse();

    foreach ($shape === false ? [] : $shape as $property) {
        expect($property)->toBe(['class' => null, 'list' => false]);
    }
});

uses(UncachedBootTestCase::class)->in(__FILE__);

it('hydrates a self-referential body as deep as the payload actually nests', function () {
    /** @var UncachedBootTestCase $this */
    $this->postJson('/nodes', [
        'label' => 'root',
        'child' => ['label' => 'a', 'child' => ['label' => 'b', 'child' => ['label' => 'c']]],
    ])
        ->assertStatus(201)
        ->assertExactJson(['label' => 'root', 'depth' => 4]);
});

it('hydrates a nested body end to end, from a real scan through a real request', function () {
    /** @var UncachedBootTestCase $this */
    $this->postJson('/transfers', [
        'amount' => 2500,
        'beneficiary' => [
            'street' => 'Calle Mayor 1',
            'postcode' => '28013',
            'geo' => ['lat' => 40.4168, 'lon' => -3.7038],
        ],
        'lines' => [
            ['reference' => 'INV-1', 'cents' => 1500],
            ['reference' => 'INV-2', 'cents' => 1000],
        ],
    ])
        ->assertStatus(201)
        ->assertExactJson(['amount' => 2500, 'postcode' => '28013', 'lines' => 2]);
});
