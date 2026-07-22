<?php

declare(strict_types=1);

use Firefly\Data\Repository\Query\DerivedQueryParser;

/**
 * @param  list<array{field: string, op: string, ignoreCase: bool, boolLiteral: bool|null}>  $expected
 */
it('parses every operator into a single predicate (default find prefix)', function (string $method, array $expected) {
    $parsed = DerivedQueryParser::parse($method);

    expect($parsed->prefix)->toBe('find')
        ->and($parsed->toArray()['predicates'])->toBe($expected);
})->with([
    'equals (default)' => ['findByStatus', [['field' => 'status', 'op' => 'Equals', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'greaterThan' => ['findByAmountGreaterThan', [['field' => 'amount', 'op' => 'GreaterThan', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'greaterThanEqual' => ['findByAmountGreaterThanEqual', [['field' => 'amount', 'op' => 'GreaterThanEqual', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'lessThan' => ['findByAmountLessThan', [['field' => 'amount', 'op' => 'LessThan', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'lessThanEqual' => ['findByAmountLessThanEqual', [['field' => 'amount', 'op' => 'LessThanEqual', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'between' => ['findByAmountBetween', [['field' => 'amount', 'op' => 'Between', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'like' => ['findByNameLike', [['field' => 'name', 'op' => 'Like', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'notLike' => ['findByNameNotLike', [['field' => 'name', 'op' => 'NotLike', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'in' => ['findByTagsIn', [['field' => 'tags', 'op' => 'In', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'notIn' => ['findByTagsNotIn', [['field' => 'tags', 'op' => 'NotIn', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'containing' => ['findByNameContaining', [['field' => 'name', 'op' => 'Containing', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'startingWith' => ['findByNameStartingWith', [['field' => 'name', 'op' => 'StartingWith', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'endingWith' => ['findByNameEndingWith', [['field' => 'name', 'op' => 'EndingWith', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'isNull' => ['findByDeletedAtIsNull', [['field' => 'deleted_at', 'op' => 'IsNull', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'isNotNull' => ['findByDeletedAtIsNotNull', [['field' => 'deleted_at', 'op' => 'IsNotNull', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'not' => ['findByStatusNot', [['field' => 'status', 'op' => 'Not', 'ignoreCase' => false, 'boolLiteral' => null]]],
    'true' => ['findByActiveTrue', [['field' => 'active', 'op' => 'True', 'ignoreCase' => false, 'boolLiteral' => true]]],
    'false' => ['findByActiveFalse', [['field' => 'active', 'op' => 'False', 'ignoreCase' => false, 'boolLiteral' => false]]],
    'ignoreCase' => ['findByEmailIgnoreCase', [['field' => 'email', 'op' => 'Equals', 'ignoreCase' => true, 'boolLiteral' => null]]],
    'likeIgnoreCase' => ['findByNameLikeIgnoreCase', [['field' => 'name', 'op' => 'Like', 'ignoreCase' => true, 'boolLiteral' => null]]],
]);

it('parses the prefix family, First / Top{N} / Distinct modifiers', function (string $method, string $prefix, ?int $top, bool $distinct) {
    $parsed = DerivedQueryParser::parse($method);

    expect($parsed->prefix)->toBe($prefix)
        ->and($parsed->top)->toBe($top)
        ->and($parsed->distinct)->toBe($distinct);
})->with([
    'find' => ['findByStatus', 'find', null, false],
    'count' => ['countByStatus', 'count', null, false],
    'exists' => ['existsByStatus', 'exists', null, false],
    'delete' => ['deleteByStatus', 'delete', null, false],
    'first' => ['findFirstByStatus', 'find', 1, false],
    'top3' => ['findTop3ByStatus', 'find', 3, false],
    'distinct' => ['findDistinctByStatus', 'find', null, true],
]);

it('parses And / Or connectors between predicates', function () {
    $and = DerivedQueryParser::parse('findByStatusAndAmountGreaterThan');
    expect($and->connectors)->toBe(['And'])
        ->and($and->predicates)->toHaveCount(2)
        ->and($and->predicates[0]->field)->toBe('status')
        ->and($and->predicates[0]->op)->toBe('Equals')
        ->and($and->predicates[1]->field)->toBe('amount')
        ->and($and->predicates[1]->op)->toBe('GreaterThan');

    $or = DerivedQueryParser::parse('findByStatusOrType');
    expect($or->connectors)->toBe(['Or'])
        ->and($or->predicates[1]->field)->toBe('type');

    // A field whose name simply STARTS with "Or"/"And" must not be mis-split.
    $order = DerivedQueryParser::parse('findByOrderId');
    expect($order->connectors)->toBe([])
        ->and($order->predicates)->toHaveCount(1)
        ->and($order->predicates[0]->field)->toBe('order_id');
});

it('parses chainable OrderBy clauses', function () {
    expect(DerivedQueryParser::parse('findByStatusOrderByAmountDesc')->toArray()['orders'])
        ->toBe([['field' => 'amount', 'dir' => 'desc']]);

    expect(DerivedQueryParser::parse('findByStatusOrderByAmountDescNameAsc')->toArray()['orders'])
        ->toBe([['field' => 'amount', 'dir' => 'desc'], ['field' => 'name', 'dir' => 'asc']]);

    // No explicit direction defaults to asc; multi-word field maps camel->snake.
    expect(DerivedQueryParser::parse('findByStatusOrderByCreatedAt')->toArray()['orders'])
        ->toBe([['field' => 'created_at', 'dir' => 'asc']]);
});

it('honours LONGEST-MATCH operator ordering (the tripwire)', function () {
    // NotIn must beat In, NotLike must beat Like, IsNotNull must beat IsNull, GreaterThanEqual must not
    // degrade to GreaterThan + a stray "Equal". Reverse DerivedQueryParser::OPERATORS (shortest-first) and
    // every one of these flips (e.g. StatusNotIn -> field "statusNot" op "In"), failing this test.
    expect(DerivedQueryParser::parse('findByTagsNotIn')->predicates[0]->op)->toBe('NotIn')
        ->and(DerivedQueryParser::parse('findByTagsNotIn')->predicates[0]->field)->toBe('tags')
        ->and(DerivedQueryParser::parse('findByNameNotLike')->predicates[0]->op)->toBe('NotLike')
        ->and(DerivedQueryParser::parse('findByDeletedAtIsNotNull')->predicates[0]->op)->toBe('IsNotNull')
        ->and(DerivedQueryParser::parse('findByAmountGreaterThanEqual')->predicates[0]->op)->toBe('GreaterThanEqual')
        ->and(DerivedQueryParser::parse('findByAmountGreaterThanEqual')->predicates[0]->field)->toBe('amount');
});

it('throws on an unparseable method name', function () {
    expect(fn () => DerivedQueryParser::parse('save'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DerivedQueryParser::parse('findAll'))->toThrow(InvalidArgumentException::class);
});

it('rejects a field that does not reduce to a bare column identifier (injection guard)', function (string $method) {
    // The derived field is interpolated into a raw LOWER(col) fragment on the IgnoreCase path. parse() is
    // reachable from the public EloquentRepository::__call, so a crafted method name whose "field" carries SQL
    // must be refused here, not trusted. Deleting the identifier guard in toSnake() makes every one of these
    // parse successfully and flips this test.
    expect(fn () => DerivedQueryParser::parse($method))->toThrow(InvalidArgumentException::class);
})->with([
    'quote + boolean tautology' => ["findByemail) = 'zzz' or 1=1 or lower(emailIgnoreCase"],
    'space in field' => ['findByStatus OrType'],
    'parenthesis in field' => ['findBycount(*)'],
    'semicolon / stacked statement' => ['findByid; drop table users'],
]);
