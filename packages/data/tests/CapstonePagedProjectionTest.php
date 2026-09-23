<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Tests\Fixtures\Capstone\Account;
use Firefly\Data\Tests\Fixtures\Capstone\AccountRepository;
use Firefly\Data\Tests\Fixtures\Capstone\AccountSummary;
use Firefly\Data\Tests\Support\DataCapstoneTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

uses(DataCapstoneTestCase::class);

/**
 * A #[Projection] with a trailing Pageable through the WHOLE pipeline: a real container, the compiled
 * component/transactional manifests, the shipped BeanPostProcessor and the generated transactional proxy. What
 * that adds over the hand-built suite in tests/Repository is the one thing a hand-built repository cannot show
 * — that the DataSettings bean DataAutoConfiguration builds from `firefly.data.projection.pageable` is
 * autowired into a container-built #[Repository] and SURVIVES the proxy's state copy.
 */
function capstoneAccountRepository(Application $app): AccountRepository
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var AccountRepository $repository */
    $repository = $context->get(AccountRepository::class);

    return $repository;
}

function seedAccounts(AccountRepository $repository, int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        $repository->save(new Account(['name' => 'ada']));
    }
}

it('pages a projection through the container-built, transactionally-proxied repository', function () {
    /** @var DataCapstoneTestCase $this */
    $repository = capstoneAccountRepository($this->app());
    seedAccounts($repository, 25);
    DB::enableQueryLog();

    $page = $repository->findByName('ada', Pageable::of(2, 10, Sort::by('id')));
    $onAccounts = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "accounts"')));

    expect($repository::class)->toBe(AccountRepository::class.'__FireflyTransactionalProxy')
        ->and($page)->toBeInstanceOf(Page::class)
        ->and($page->items)->toHaveCount(10)
        ->and($page->items[0])->toBeInstanceOf(AccountSummary::class)
        ->and($page->items[0]->id)->toBe(11)
        ->and($page->total)->toBe(25)
        ->and($page->hasNext())->toBeTrue()
        ->and($onAccounts)->toBe([
            'select count(*) as "aggregate" from "accounts" where "name" = ?',
            'select "id", "name" from "accounts" where "name" = ? order by "id" asc limit 10 offset 10',
        ]);
});
