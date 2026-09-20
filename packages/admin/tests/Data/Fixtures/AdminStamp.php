<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The one fixture with Eloquent's timestamps ON. Every other model here switches them off so the tests own
 * every column; this one exists to prove that a create from the generic form leaves `created_at` and
 * `updated_at` to the model — a blank in a field the person did not have to fill must be OMITTED from the
 * insert, because an explicit null is "dirty" to Eloquent and silences its own timestamping.
 *
 * @property int $id
 * @property string $label
 * @property string|null $created_at
 * @property string|null $updated_at
 */
final class AdminStamp extends Model
{
    protected $table = 'admin_stamps';

    protected $guarded = [];
}
