<?php

declare(strict_types=1);

namespace App\Orders;

use Illuminate\Database\Eloquent\Model;

/**
 * The persistence shape of an order — an ordinary Eloquent model over the `orders` table.
 *
 * IT IS A THIRD CLASS ON PURPOSE, and the skeleton now has all three: App\Http\OrderRequest is the order as
 * it arrives over HTTP (validation attributes, wire names), App\Orders\Order is the order as the domain
 * understands it (immutable, derives its own total), and this is the order as a row. Each changes for its
 * own reason — a column rename must not alter a published API, and a new API field must not force a
 * migration — which is the whole argument for not letting one class do all three jobs.
 *
 * The mapping between this and Order lives in OrderService, the same place Spring puts it when a repository
 * returns entities and the use cases speak in domain types.
 *
 * `ship_to` and `lines` are cast to arrays because they are json columns; `total` is a decimal column, and
 * PDO hands decimals back as strings, so without the cast the API's `total` would silently change from a
 * number to a string the first time the value came from the database instead of from Order::total().
 */
class OrderEntity extends Model
{
    protected $table = 'orders';

    /** @var list<string> */
    protected $fillable = ['customer', 'email', 'ship_to', 'lines', 'total'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ship_to' => 'array',
            'lines' => 'array',
            'total' => 'float',
        ];
    }
}
