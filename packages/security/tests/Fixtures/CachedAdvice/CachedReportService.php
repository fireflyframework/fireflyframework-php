<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\CachedAdvice;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PostAuthorize;
use Firefly\Security\Access\Attributes\PostFilter;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Security\Access\Attributes\PreFilter;
use Firefly\Security\Tests\Fixtures\Advice\Report;

/**
 * The Advice/ReportService shape — a plain #[Service] with no #[Transactional] carrying every kind of rule, NOT
 * final — under a namespace of its own, because a proxy class is declared once per PHP process: were the cached
 * boot to share Advice/ReportService with the uncached one, whichever ran first would own
 * ReportService__FireflyTransactionalProxy and the cached suite could no longer tell a proxy loaded from the
 * cache directory's classmap from one the uncached ProxyMaterializer generated into a temp directory. This
 * class is proxied ONLY through the compiled proxy-plan.php + proxies.php the cached base writes for it.
 */
#[Service]
class CachedReportService
{
    /** @return array<string, int> */
    #[PreAuthorize("hasRole('ADMIN')")]
    public function totals(): array
    {
        return ['total' => 42];
    }

    #[PostAuthorize("hasPermission(#returnObject, 'READ')", code: 'REPORT_NOT_YOURS', message: 'That report belongs to someone else.')]
    public function find(int $id): Report
    {
        return new Report($id, $id % 2 === 0 ? 'ada' : 'bob');
    }

    /** @return list<Report> */
    #[PostFilter("hasPermission(#filterObject, 'READ')")]
    public function all(): array
    {
        return [new Report(1, 'bob'), new Report(2, 'ada'), new Report(3, 'bob'), new Report(4, 'ada')];
    }

    /**
     * @param  list<int>  $ids
     * @return array{purged: list<int>, reason: string}
     */
    #[PreFilter("hasPermission(#filterObject, 'WRITE')", filterTarget: 'ids')]
    public function purge(array $ids, string $reason): array
    {
        return ['purged' => $ids, 'reason' => $reason];
    }

    public function unguarded(): string
    {
        return 'open';
    }
}
