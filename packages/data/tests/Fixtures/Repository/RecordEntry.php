<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Illuminate\Database\Eloquent\Model;

/**
 * The child half of the entity-graph fixture: `record_entries.record_id` points at `records.id`.
 *
 * @property int $id
 * @property int $record_id
 * @property string $note
 */
final class RecordEntry extends Model
{
    protected $table = 'record_entries';

    public $timestamps = false;

    protected $guarded = [];
}
