<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A JOIN TABLE, WHICH IS TO SAY A RESOURCE WITH NOTHING TO SEARCH.
 *
 * Every other Eloquent fixture here has a string column, and that is exactly why the data browser could
 * render a search box that answers nothing for a year without a test noticing. `DataSchema::searchable()`
 * keeps only non-sensitive `string` columns, so this table — four integers, the shape half the pivot tables
 * in any schema have — publishes none, and `DataQueryEngine::fetch()` short-circuits to `[[], 0]` for every
 * term. It is a real Eloquent model over a real sqlite table rather than a stub because the point is what
 * the LIVE schema derivation makes of it.
 *
 * @property int $id
 * @property int $record_id
 * @property int $entry_id
 * @property int $quantity
 */
final class AdminLink extends Model
{
    protected $table = 'admin_links';

    public $timestamps = false;

    protected $guarded = [];
}
