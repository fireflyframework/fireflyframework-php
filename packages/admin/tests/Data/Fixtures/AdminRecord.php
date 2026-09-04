<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;

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
}
