<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Auditing;

use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in auditing: `use Auditable` on an Eloquent model with created_by/updated_by columns. Eloquent auto-invokes
 * the `bootAuditable` boot hook. Registration is deferred via the native `whenBooted()` hook (not called eagerly
 * from `bootAuditable` itself): `bootAuditable` runs from inside `Model::boot()`, while `static::$booted[...]` is
 * still unset, and `Model::observe()` does `new static` internally — instantiating the model at that point trips
 * Eloquent's own "may not be called on model while it is being booted" guard. `whenBooted()` queues the observer
 * registration to run once boot has actually completed, sidestepping that re-entrancy. Stamping is driven by a
 * bound AuditorAware (see AuditObserver); with none bound it no-ops.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::whenBooted(fn () => static::observe(AuditObserver::class));
    }
}
