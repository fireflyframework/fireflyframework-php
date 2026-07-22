<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 */
final class SoftRecord extends Model
{
    use SoftDeletes;

    protected $table = 'soft_records';

    public $timestamps = false;

    protected $guarded = [];
}
