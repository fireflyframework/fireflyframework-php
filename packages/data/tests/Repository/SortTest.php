<?php

declare(strict_types=1);

use Firefly\Data\Repository\Direction;
use Firefly\Data\Repository\Order;
use Firefly\Data\Repository\Sort;

it('builds an ascending sort from property names', function () {
    $sort = Sort::by('status', 'amount');

    expect($sort->orders)->toHaveCount(2)
        ->and($sort->orders[0]->property)->toBe('status')
        ->and($sort->orders[0]->direction)->toBe(Direction::Asc)
        ->and($sort->isSorted())->toBeTrue();
});

it('reports the empty sort as unsorted', function () {
    expect(Sort::unsorted()->isSorted())->toBeFalse()
        ->and(Sort::unsorted()->orders)->toBe([]);
});

it('concatenates two sorts', function () {
    $sort = Sort::by('a')->and(Sort::by('b')->descending());

    expect($sort->orders)->toHaveCount(2)
        ->and($sort->orders[0]->property)->toBe('a')
        ->and($sort->orders[0]->direction)->toBe(Direction::Asc)
        ->and($sort->orders[1]->property)->toBe('b')
        ->and($sort->orders[1]->direction)->toBe(Direction::Desc);
});

it('flips every order ascending or descending', function () {
    $sort = Sort::by('a', 'b');

    expect($sort->descending()->orders[0]->direction)->toBe(Direction::Desc)
        ->and($sort->descending()->orders[1]->direction)->toBe(Direction::Desc)
        ->and($sort->descending()->ascending()->orders[0]->direction)->toBe(Direction::Asc);
});

it('exposes asc/desc factories on Order', function () {
    expect(Order::asc('x')->isAscending())->toBeTrue()
        ->and(Order::desc('x')->isAscending())->toBeFalse()
        ->and(Order::desc('x')->direction->value)->toBe('desc');
});
