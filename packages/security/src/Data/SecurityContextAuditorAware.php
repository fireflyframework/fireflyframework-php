<?php

declare(strict_types=1);

namespace Firefly\Security\Data;

use Firefly\Data\Repository\Auditing\AuditorAware;
use Firefly\Security\Core\SecurityContextHolder;

/**
 * Lights up the M8 auditing seam: returns the current authenticated principal's id (its name) so the AuditObserver
 * stamps created_by/updated_by. Returns null when anonymous — the AuditObserver already no-ops on null, leaving the
 * columns untouched for unauthenticated writes (a scheduled job, a public endpoint).
 */
final class SecurityContextAuditorAware implements AuditorAware
{
    public function currentAuditor(): ?string
    {
        $authentication = SecurityContextHolder::getAuthentication();

        return $authentication !== null && $authentication->isAuthenticated() ? $authentication->getName() : null;
    }
}
