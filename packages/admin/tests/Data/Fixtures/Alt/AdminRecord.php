<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures\Alt;

use Illuminate\Database\Eloquent\Model;

/**
 * A SECOND class called AdminRecord, in a different namespace, over a different table. Two bounded contexts
 * each owning an `AdminRecord` is unremarkable in a real application, and it is the case that breaks a naive
 * short-name slug.
 *
 * @property int $id
 * @property string $label
 */
final class AdminRecord extends Model
{
    protected $table = 'alt_admin_records';

    public $timestamps = false;

    protected $guarded = [];
}
