<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The one table the data-surfaces scenarios need that the skeleton does not have: a UNIQUE column. The
 * skeleton's `orders.email` is indexed, not unique, so a duplicate through the data browser needs this
 * fixture — an ordinary Eloquent model, nothing overridden, so the browser reads the ports a real one exposes.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 */
final class BrowserSubscriber extends Model
{
    protected $table = 'browser_subscribers';

    public $timestamps = false;

    protected $guarded = [];
}
