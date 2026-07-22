<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Auditing;

/**
 * The auditing seam: who is acting now. M11's security context binds an implementation; until then nothing is
 * bound and the AuditObserver no-ops (created_by/updated_by stay null). Kept in firefly/data so the observer can
 * depend on the port without a hard M11 dependency.
 */
interface AuditorAware
{
    public function currentAuditor(): int|string|null;
}
