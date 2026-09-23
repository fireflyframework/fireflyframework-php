<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\Attributes\Projection;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * The combination EloquentRepository::repositoryClass() exists for: a #[Repository] that is ALSO class-level
 * #[Transactional], so the bean the context hands out is the generated `AccountRepository__FireflyTransactionalProxy`
 * subclass and every public method — the inherited CRUD contract and the #[Query] method below — runs through the
 * TransactionInterceptor. The derived query (countByName, resolved by __call, which the scanner never proxies)
 * and the #[Query] method both reach dispatchQuery() with static::class = the proxy, which is exactly what the
 * manifest lookups must see through. NOT `final` — the proxy extends it.
 *
 * @extends EloquentRepository<Account>
 *
 * @method int countByName(string $name)
 */
#[Repository]
#[Transactional]
class AccountRepository extends EloquentRepository
{
    protected string $model = Account::class;

    /**
     * @return list<array<string, mixed>>
     */
    #[Query('select * from accounts where name = :name order by id asc')]
    public function findByNameRaw(string $name): array
    {
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert(is_array($rows));

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * A #[Projection] with a TRAILING PAGEABLE, through the container and through the transactional proxy: the
     * DataSettings bean DataAutoConfiguration builds from firefly.data.projection.pageable is what decides
     * whether it pages, so this method is how a booted application's answer is asserted rather than a
     * hand-built repository's.
     *
     * @return Page<AccountSummary>
     */
    #[Projection(AccountSummary::class)]
    public function findByName(string $name, Pageable $pageable): Page
    {
        $page = $this->dispatchQuery(__FUNCTION__, func_get_args());
        assert($page instanceof Page);

        /** @var Page<AccountSummary> $page */
        return $page;
    }

    /**
     * The same projection typed the way an application that has not migrated its call sites types it — a list,
     * because a list is what it used to get. With firefly.data.projection.pageable off it still gets one.
     *
     * @return list<AccountSummary>
     */
    #[Projection(AccountSummary::class)]
    public function findByNameOrderByIdAsc(string $name, Pageable $pageable): array
    {
        /** @var list<AccountSummary> $rows */
        $rows = $this->dispatchQuery(__FUNCTION__, func_get_args());

        return $rows;
    }
}
