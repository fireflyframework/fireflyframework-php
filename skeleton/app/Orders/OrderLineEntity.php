<?php

declare(strict_types=1);

namespace App\Orders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order, as a row.
 *
 * IT HAS A TABLE BECAUSE IT HAS AN IDENTITY. An address is embedded in the order as a json column — nothing
 * queries for an address and nothing refers to one — while a line is a thing you count, sum and search
 * across, so it gets a table and a foreign key. That is the same call Spring asks you to make between an
 * @Embeddable and an @Entity, and it is the whole reason this sample has two tables and not one.
 *
 * `order()` is why the admin dashboard can walk from a line back to its order. firefly/admin discovers a
 * relation by its declared RETURN TYPE — a method announcing `: BelongsTo` is a relation definition by
 * construction — so the annotation below is not decoration: delete it and the dashboard stops offering the
 * link, while Eloquent carries on working exactly as before.
 */
class OrderLineEntity extends Model
{
    protected $table = 'order_lines';

    /** @var list<string> */
    protected $fillable = ['order_id', 'sku', 'quantity', 'unit_price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'quantity' => 'integer',
            // PDO hands decimals back as strings; without the cast an API's `unitPrice` would silently
            // change from a number to a string the first time it came from the database.
            'unit_price' => 'float',
        ];
    }

    /** @return BelongsTo<OrderEntity, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(OrderEntity::class);
    }
}
