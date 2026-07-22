<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Data\Repository\Locking\HasOptimisticLock;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int $version
 */
final class VersionedRecord extends Model
{
    use HasOptimisticLock;

    protected $table = 'versioned_records';

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = ['version' => 0];
}
