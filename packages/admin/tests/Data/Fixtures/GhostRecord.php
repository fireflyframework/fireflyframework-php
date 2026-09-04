<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose table is not there — a migration that has not run yet, or a connection pointed at the wrong
 * database. The browser still knows the key name, so the resource degrades to a key-only listing that says
 * where its columns came from, instead of disappearing from the menu with no explanation.
 *
 * @property int $id
 */
final class GhostRecord extends Model
{
    protected $table = 'ghost_records';

    public $timestamps = false;

    protected $guarded = [];
}
