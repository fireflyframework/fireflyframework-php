<?php

declare(strict_types=1);

use Firefly\OpenApi\Schema\ElementTypes;
use Firefly\OpenApi\Tests\NestedFixture\CreateOrderRequest;
use Firefly\OpenApi\Tests\NestedFixture\Fulfilment;
use Firefly\OpenApi\Tests\NestedFixture\OrderLineRequest;
use Firefly\Validation\Valid;

it('reads #[Valid(each:)] on the reflective fallback, exactly as the hydration table would carry it', function () {
    expect((new ElementTypes)->forClass(EachOnlyRequest::class))->toBe(['lines' => EachOnlyLine::class]);
});

it('still reads the constructor @param on the reflective fallback', function () {
    expect((new ElementTypes)->forClass(CreateOrderRequest::class))->toBe([
        'lines' => OrderLineRequest::class,
        'channels' => Fulfilment::class,
    ]);
});

it('prefers the compiled table over reflection when it has a row', function () {
    $table = [EachOnlyRequest::class => ['lines' => ['class' => null, 'list' => false]]];

    expect((new ElementTypes($table))->forClass(EachOnlyRequest::class))->toBe([]);
});

final class EachOnlyLine
{
    public function __construct(public readonly string $sku) {}
}

final class EachOnlyRequest
{
    // @phpstan-ignore missingType.iterableValue (deliberately no docblock: the element class is stated by each: alone)
    public function __construct(
        #[Valid(each: EachOnlyLine::class)]
        public readonly array $lines = [],
    ) {}
}
