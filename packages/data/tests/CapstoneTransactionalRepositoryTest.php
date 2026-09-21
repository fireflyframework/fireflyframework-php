<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Tests\Fixtures\Capstone\Account;
use Firefly\Data\Tests\Fixtures\Capstone\AccountRepository;
use Firefly\Data\Tests\Support\DataCapstoneTestCase;
use Firefly\Kernel\Exception\Infrastructure\BadSqlGrammarException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

uses(DataCapstoneTestCase::class);

/**
 * A #[Repository] that is ALSO #[Transactional] — the combination EloquentRepository::repositoryClass() exists for.
 * The bean the context hands out is the generated proxy subclass (real container, compiled manifest, shipped
 * BeanPostProcessor), so every assertion below runs with static::class = `AccountRepository__FireflyTransactionalProxy`:
 * the manifest lookups must see through it, and the state ProxyFactory copied must include what EloquentRepository
 * holds privately (the translator) and readonly (the manifest, the tracker).
 */
function accountRepository(Application $app): AccountRepository
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var AccountRepository $repository */
    $repository = $context->get(AccountRepository::class);

    return $repository;
}

it('hands out the generated proxy subclass for a #[Repository] that is also #[Transactional]', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = accountRepository($this->app());

    expect($repository::class)->toBe(AccountRepository::class.'__FireflyTransactionalProxy')
        ->and($repository)->toBeInstanceOf(AccountRepository::class);
});

it('throws DuplicateKeyException with the QueryException underneath from the PROXIED save(), rolled back', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = accountRepository($this->app());
    $repository->save(new Account(['id' => 1, 'name' => 'first']));

    try {
        $repository->save(new Account(['id' => 1, 'name' => 'second']));
        Assert::fail('expected a DuplicateKeyException');
    } catch (DuplicateKeyException $e) {
        expect($e->getPrevious())->toBeInstanceOf(QueryException::class)
            ->and($e->httpStatus())->toBe(409)
            ->and($e->extensions())->toBe(['sqlState' => '23000']);
    }

    expect(DB::table('accounts')->pluck('name')->all())->toBe(['first'])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('resolves a #[Query] method through the proxy from the manifest keyed on the DECLARED class', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = accountRepository($this->app());
    $repository->save(new Account(['name' => 'ada']));
    $repository->save(new Account(['name' => 'grace']));
    $repository->save(new Account(['name' => 'ada']));

    // The manifest's SQL is `... where name = :name order by id asc`. Had the lookup been keyed on the proxy class,
    // queriesFor() would be empty and the name would fall into the derived-query parser as `findBy NameRaw` — a
    // `name_raw` column that does not exist — so two rows for 'ada' is proof the manifest SQL ran.
    $rows = $repository->findByNameRaw('ada');

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'name'))->toBe(['ada', 'ada'])
        ->and(array_column($rows, 'id'))->toBe([1, 3]); // `order by id asc`: the first and third saves
});

it('drives a derived query through the proxy via the inherited __call', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = accountRepository($this->app());
    $repository->save(new Account(['name' => 'ada']));
    $repository->save(new Account(['name' => 'ada']));
    $repository->save(new Account(['name' => 'grace']));

    expect($repository->countByName('ada'))->toBe(2);
});

it('names the DECLARED class, not the proxy, when a method is neither #[Query] nor derivable', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = accountRepository($this->app());

    try {
        $repository->__call('frobnicate', []); // what `$repository->frobnicate()` compiles to, made explicit for PHPStan
        Assert::fail('expected a BadMethodCallException');
    } catch (BadMethodCallException $e) {
        expect($e->getMessage())->toBe(AccountRepository::class.'::frobnicate() is neither a #[Query] method nor a parseable derived query.')
            ->and($e->getMessage())->not->toContain('__FireflyTransactionalProxy');
    }
});

it('translates a #[Query] driver failure through the proxy and leaves no transaction open', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = accountRepository($this->app());
    Schema::drop('accounts');

    expect(fn () => $repository->findByNameRaw('ada'))->toThrow(BadSqlGrammarException::class)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
