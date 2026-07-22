<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Auditing;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Stamps created_by (on insert) and updated_by (on insert + update) from the bound AuditorAware. When no
 * AuditorAware is bound (pre-M11) it no-ops, leaving the columns untouched.
 */
final class AuditObserver
{
    public function creating(Model $model): void
    {
        $auditor = $this->currentAuditor();
        if ($auditor === null) {
            return;
        }

        $model->setAttribute('created_by', $auditor);
        $model->setAttribute('updated_by', $auditor);
    }

    public function updating(Model $model): void
    {
        $auditor = $this->currentAuditor();
        if ($auditor === null) {
            return;
        }

        $model->setAttribute('updated_by', $auditor);
    }

    private function currentAuditor(): int|string|null
    {
        $container = Container::getInstance();
        if (! $container->bound(AuditorAware::class)) {
            return null;
        }

        $aware = $container->make(AuditorAware::class);

        return $aware->currentAuditor();
    }
}
