<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Ordering;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * A persisted Eloquent model that IS an auto-dispatching aggregate — the idiomatic active-record DDD path:
 * `use HasDomainEvents` + `implements RecordsDomainEvents` (single-inheritance-friendly). place() raises
 * OrderPlaced into the pending buffer; EloquentRepository::save() persists the row AND tracks the recorder, and the
 * framework drains + publishes the event only after the surrounding #[Transactional] commit. NOT final only where a
 * proxy would extend it — a repository/service is proxied, a Model is not, so `final` is fine here. Timestamps are
 * hand-managed (a plain string `created_at`) so the derived-query ordering is deterministic.
 *
 * @property int $id
 * @property string $status
 * @property string $created_at
 */
final class Order extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];

    public function place(): void
    {
        $this->raiseEvent(new OrderPlaced((string) $this->status));
    }
}
