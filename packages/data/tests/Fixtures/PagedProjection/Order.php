<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\PagedProjection;

use Illuminate\Database\Eloquent\Model;

/**
 * A plain Eloquent fixture over the `orders` table — the wide list table a #[Projection] exists for, big
 * enough that handing back every matching row instead of the requested page is the difference the feature is
 * about. Guarded=[] opens mass-assignment for terse seeding; timestamps off keeps the schema minimal.
 *
 * @property int $id
 * @property string $status
 * @property string $customer
 * @property int $amount
 */
final class Order extends Model
{
    protected $table = 'orders';

    public $timestamps = false;

    protected $guarded = [];
}
