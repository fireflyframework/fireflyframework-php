<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PostAuthorize;

/**
 * A class-level #[PostAuthorize] (Spring semantics: it applies to every public method, and a method-level one
 * REPLACES it for that method — the code and sentence go with it, they are not merged). NOT `final` so the
 * proxy can extend it.
 */
#[Service]
#[PostAuthorize("hasPermission(#returnObject, 'READ')", code: 'REPORT_NOT_YOURS', message: 'That report belongs to someone else.')]
class OwnedReportService
{
    /** Inherits the class rule. */
    public function find(int $id): Report
    {
        return new Report($id, $id % 2 === 0 ? 'ada' : 'bob');
    }

    /** Replaces the class rule with its own: anyone may read the latest report. */
    #[PostAuthorize('permitAll()')]
    public function latest(): Report
    {
        return new Report(1, 'bob');
    }
}
