<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent model over the capstone's `accounts` table (id, name), so the #[Transactional] #[Repository]
 * fixture has an aggregate to save. Guarded=[] opens mass-assignment for terse seeding (an explicit duplicate `id`
 * is how a test provokes a primary-key violation); timestamps off keeps the schema minimal.
 *
 * @property int $id
 * @property string $name
 */
final class Account extends Model
{
    protected $table = 'accounts';

    public $timestamps = false;

    protected $guarded = [];
}
