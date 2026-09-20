<?php

declare(strict_types=1);

use Firefly\Data\Repository\Example\Example;
use Firefly\Data\Repository\Example\ExampleMatcher;
use Firefly\Data\Repository\Example\GenericPropertyMatcher;
use Firefly\Data\Repository\Example\StringMatcher;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Repository\Specification\Specifications;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Kernel\Exception\Infrastructure\IncorrectResultSizeDataAccessException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable();
    });

    foreach ([
        ['status' => 'open', 'amount' => 50, 'email' => 'a@x.test'],
        ['status' => 'open', 'amount' => 150, 'email' => 'b@x.test'],
        ['status' => 'closed', 'amount' => 250, 'email' => 'C@X.TEST'],
        ['status' => 'open', 'amount' => 5, 'email' => null],
        ['status' => 'closed', 'amount' => 7, 'email' => 'under_score@y.test'],
        ['status' => 'closed', 'amount' => 8, 'email' => 'underXscore@y.test'],
    ] as $row) {
        Record::query()->create($row);
    }
});

/**
 * @param  list<Record>  $records
 * @return list<string|null>
 */
function emailsOf(array $records): array
{
    return array_map(static fn (Record $r): ?string => $r->email, $records);
}

it('matches every non-null attribute of an array probe with AND, and a model probe the same way', function () {
    $repo = new RecordRepository;

    expect($repo->findByExample(Example::of(['status' => 'open', 'amount' => 150])))->toHaveCount(1)
        ->and($repo->findByExample(Example::of(new Record(['status' => 'closed']))))->toHaveCount(3)
        ->and($repo->countByExample(Example::of(['status' => 'open'])))->toBe(3)
        ->and($repo->existsByExample(Example::of(['status' => 'archived'])))->toBeFalse()
        ->and($repo->findByExample(Example::of([])))->toHaveCount(6);
});

it('reads a persisted model probe as its current attributes: every column, and a change made since the fetch', function () {
    $repo = new RecordRepository;
    $persisted = Record::query()->where('amount', '=', 150)->firstOrFail();

    // A fetched row probes on every column it carries (id included) — so it matches exactly itself...
    expect(Example::of($persisted)->probe)->toHaveKeys(['id', 'status', 'amount', 'email'])
        ->and($repo->findByExample(Example::of($persisted, ExampleMatcher::matching()->withIgnorePaths('id'))))->toHaveCount(1);

    // ...and an attribute changed after the fetch contributes its NEW value, not the persisted one.
    $persisted->amount = 250;
    $persisted->status = 'closed';

    expect(Example::of($persisted, ExampleMatcher::matching()->withIgnorePaths('id', 'email'))->attributes())->toBe(['status' => 'closed', 'amount' => 250])
        ->and($repo->findByExample(Example::of($persisted, ExampleMatcher::matching()->withIgnorePaths('id', 'email'))))->toHaveCount(1);
});

it('ignores a null probe value unless the matcher includes nulls', function () {
    $repo = new RecordRepository;

    expect($repo->findByExample(Example::of(['status' => 'open', 'email' => null])))->toHaveCount(3)
        ->and($repo->findByExample(Example::of(['status' => 'open', 'email' => null], ExampleMatcher::matching()->withIncludeNullValues())))->toHaveCount(1);
});

it('folds with OR under matchingAny', function () {
    $rows = (new RecordRepository)->findByExample(Example::of(['status' => 'open', 'amount' => 250], ExampleMatcher::matchingAny()));

    expect($rows)->toHaveCount(4);
});

it('applies CONTAINING / STARTING / ENDING to string values only, escaping LIKE metacharacters', function () {
    $repo = new RecordRepository;

    // sqlite's LIKE is case-insensitive for ASCII (so is mysql's under a *_ci collation), so a CONTAINING
    // assertion never leans on case; the case tests below use `=` and LOWER() explicitly.
    expect($repo->findByExample(Example::of(['email' => 'y.test'], ExampleMatcher::matching()->withStringMatcher(StringMatcher::CONTAINING))))->toHaveCount(2)
        ->and($repo->findByExample(Example::of(['email' => 'b@'], ExampleMatcher::matching()->withStringMatcher(StringMatcher::STARTING))))->toHaveCount(1)
        ->and($repo->findByExample(Example::of(['email' => '@y.test'], ExampleMatcher::matching()->withStringMatcher(StringMatcher::ENDING))))->toHaveCount(2)
        // `_` is a LIKE wildcard; escaped, it matches only the literal underscore row.
        ->and(emailsOf($repo->findByExample(Example::of(['email' => 'under_'], ExampleMatcher::matching()->withStringMatcher(StringMatcher::CONTAINING)))))->toBe(['under_score@y.test'])
        // an int stays an equality even under a string matcher
        ->and($repo->findByExample(Example::of(['amount' => 5], ExampleMatcher::matching()->withStringMatcher(StringMatcher::CONTAINING))))->toHaveCount(1);
});

it('ignores case globally, per path, or per property matcher', function () {
    $repo = new RecordRepository;

    expect($repo->findByExample(Example::of(['email' => 'c@x.test'])))->toHaveCount(0)
        ->and($repo->findByExample(Example::of(['email' => 'c@x.test'], ExampleMatcher::matching()->withIgnoreCase())))->toHaveCount(1)
        ->and($repo->findByExample(Example::of(['email' => 'c@x.test'], ExampleMatcher::matching()->withIgnoreCase('email'))))->toHaveCount(1)
        ->and($repo->findByExample(Example::of(['email' => 'c@x.test'], ExampleMatcher::matching()->withIgnoreCase('status'))))->toHaveCount(0)
        ->and($repo->findByExample(Example::of(['email' => 'x.test'], ExampleMatcher::matching()->withMatcher('email', GenericPropertyMatcher::contains()->ignoreCase()))))->toHaveCount(3);
});

it('drops ignored paths from the probe', function () {
    $rows = (new RecordRepository)->findByExample(Example::of(['status' => 'open', 'amount' => 999], ExampleMatcher::matching()->withIgnorePaths('amount')));

    expect($rows)->toHaveCount(3);
});

it('findOneByExample returns the row, null, or refuses an ambiguous probe', function () {
    $repo = new RecordRepository;

    expect($repo->findOneByExample(Example::of(['amount' => 150]))?->email)->toBe('b@x.test')
        ->and($repo->findOneByExample(Example::of(['amount' => 999])))->toBeNull()
        ->and(fn () => $repo->findOneByExample(Example::of(['status' => 'open'])))->toThrow(IncorrectResultSizeDataAccessException::class);
});

it('pages an example with a total, and composes with other specifications', function () {
    $repo = new RecordRepository;

    $page = $repo->findByExamplePaged(Example::of(['status' => 'open']), Pageable::of(1, 2, Sort::by('amount')->descending()));

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(2)
        ->and($page->items[0]->amount)->toBe(150);

    $combined = Specifications::allOf(
        Example::of(['status' => 'closed']),
        Specifications::where(static fn (Builder $q): Builder => $q->where('amount', '>', 100)),
    );

    expect($repo->findBySpecification($combined))->toHaveCount(1);
});
