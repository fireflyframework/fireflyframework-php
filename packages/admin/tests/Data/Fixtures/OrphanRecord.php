<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model pinned to a connection this deployment never configured — a read replica named in a shared config
 * but absent from a developer's `.env`. Asking it for its columns throws; the browser still knows its key
 * name, so the resource degrades rather than disappearing.
 *
 * @property int $id
 */
final class OrphanRecord extends Model
{
    protected $connection = 'no-such-connection';

    protected $table = 'orphan_records';

    public $timestamps = false;

    protected $guarded = [];
}
