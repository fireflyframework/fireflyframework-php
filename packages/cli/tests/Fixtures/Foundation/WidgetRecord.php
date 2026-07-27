<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent persistence model behind WidgetRepository + FoundationWriter (the EloquentRepository base needs an
 * Eloquent Model, not a pure domain Entity). Mirrors the data package's Repository\Record fixture: guarded=[] for
 * terse seeding, timestamps off for a minimal schema.
 *
 * @property int $id
 * @property string $status
 * @property int $amount
 */
final class WidgetRecord extends Model
{
    protected $table = 'widgets';

    public $timestamps = false;

    protected $guarded = [];
}
