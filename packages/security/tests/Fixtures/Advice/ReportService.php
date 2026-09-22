<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PostAuthorize;
use Firefly\Security\Access\Attributes\PostFilter;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Security\Access\Attributes\PreFilter;

/**
 * A plain #[Service] — no controller, no handler, no #[Transactional] — carrying every kind of method-security
 * rule. Nothing but the proxy can enforce these; NOT `final` so the proxy can extend it.
 */
#[Service]
class ReportService
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

    /**
     * No filterTarget: `$ids` is the sole array/iterable parameter, and it is deliberately NOT the first one,
     * so the inference has to find it by type rather than by position.
     *
     * @param  list<int>  $ids
     * @return array{archived: list<int>, reason: string}
     */
    #[PreFilter("hasPermission(#filterObject, 'WRITE')")]
    public function archive(string $reason, array $ids): array
    {
        return ['archived' => $ids, 'reason' => $reason];
    }

    public function unguarded(): string
    {
        return 'open';
    }
}
