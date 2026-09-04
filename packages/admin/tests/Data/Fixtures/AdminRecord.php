<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real Eloquent model over a real sqlite table, shaped to exercise every branch of column derivation at
 * once: a name the masker catches (`api_token`), a name it does not that the MODEL hides
 * (`recovery_phrase`), a `text` column that is only JSON because a cast says so, a `tinyint` that is only a
 * boolean because a cast says so, and a genuine datetime.
 *
 * @property int $id
 * @property string $email
 * @property string|null $api_token
 * @property string|null $recovery_phrase
 * @property int $amount
 * @property bool $active
 * @property array<string, mixed>|null $meta
 * @property string|null $created_at
 *
 * It also declares the PARENT half of the relation fixture, so the browser has an edge to walk in both
 * directions — a hasMany here and a belongsTo on AdminEntry.
 */
final class AdminRecord extends Model
{
    protected $table = 'admin_records';

    public $timestamps = false;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['recovery_phrase'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'active' => 'boolean'];
    }

    /** @return HasMany<AdminEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AdminEntry::class, 'record_id');
    }
}
