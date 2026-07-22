<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Illuminate\Database\Eloquent\Model;

/**
 * A plain Eloquent fixture over the `records` table (created per test via Schema::create). Guarded=[] opens
 * mass-assignment for terse seeding; timestamps off keeps the schema minimal.
 *
 * @property int $id
 * @property string $status
 * @property int $amount
 * @property string|null $email
 */
final class Record extends Model
{
    protected $table = 'records';

    public $timestamps = false;

    protected $guarded = [];
}
