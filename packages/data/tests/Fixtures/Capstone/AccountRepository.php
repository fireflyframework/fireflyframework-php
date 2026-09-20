<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;
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
}
