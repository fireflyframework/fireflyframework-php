<?php

declare(strict_types=1);

use Firefly\Domain\ValueObject;
use Firefly\Domain\ValueObjectEquality;

final readonly class Money implements ValueObject
{
    use ValueObjectEquality;

    public function __construct(public int $amount, public string $currency) {}
}

final readonly class Weight implements ValueObject
{
    use ValueObjectEquality;

    public function __construct(public int $amount) {}
}

it('compares value objects by value', function () {
    expect((new Money(100, 'EUR'))->equals(new Money(100, 'EUR')))->toBeTrue()
        ->and((new Money(100, 'EUR'))->equals(new Money(101, 'EUR')))->toBeFalse()
        ->and((new Money(100, 'EUR'))->equals(new Money(100, 'USD')))->toBeFalse();
});

it('is a marker for the domain', function () {
    expect(new Money(1, 'EUR'))->toBeInstanceOf(ValueObject::class);
});
