<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The child half of the relation fixture, plus the two shapes relation discovery must NOT follow.
 *
 * `record()` is a real BelongsTo and is the one method here that should be discovered. `label()` returns a
 * string and `touchedCount()` mutates a counter — both are public, both take no arguments, and neither
 * declares a Relation return type, so neither is called. That last one is the whole safety argument stated
 * as a fixture: if discovery ever widened from "declared return type is a Relation" to "looks like it might
 * be one", `$calls` would climb and the test below would say so.
 *
 * @property int $id
 * @property int $record_id
 * @property string $note
 * @property float $amount
 */
final class AdminEntry extends Model
{
    // NOT `$touches`: Eloquent's Model already declares a non-static $touches, and redeclaring it static is a
    // fatal error. A counter named after the thing it counts avoids the collision and reads better anyway.
    public static int $calls = 0;

    protected $table = 'admin_entries';

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['record_id' => 'integer', 'amount' => 'float'];
    }

    /** @return BelongsTo<AdminRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(AdminRecord::class, 'record_id');
    }

    public function label(): string
    {
        return 'entry';
    }

    public function touchedCount(): int
    {
        return ++self::$calls;
    }
}
