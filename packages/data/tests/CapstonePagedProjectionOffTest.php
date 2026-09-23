<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Tests\Fixtures\Capstone\Account;
use Firefly\Data\Tests\Fixtures\Capstone\AccountRepository;
use Firefly\Data\Tests\Fixtures\Capstone\AccountSummary;
use Firefly\Data\Tests\Support\PagedProjectionOffCapstoneTestCase;

uses(PagedProjectionOffCapstoneTestCase::class);

/**
 * The same boot with `firefly.data.projection.pageable => false`. The key is read by DataSettings::fromConfig
 * into the bean the container autowires into the repository, so this is the proof that the escape hatch is a
 * real switch an operator can flip — not a constructor argument only a test can pass.
 */
it('returns the whole unpaged projection when the application turns the key off', function () {
    /** @var PagedProjectionOffCapstoneTestCase $this */
    $app = $this->app();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var AccountRepository $repository */
    $repository = $context->get(AccountRepository::class);

    for ($i = 1; $i <= 25; $i++) {
        $repository->save(new Account(['name' => 'ada']));
    }

    $rows = $repository->findByNameOrderByIdAsc('ada', Pageable::of(1, 10));

    expect($rows)->toHaveCount(25)->and($rows[0])->toBeInstanceOf(AccountSummary::class);
});
