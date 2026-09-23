<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Database\Eloquent\Model;

/**
 * A badge, of which only an allow-listed part is ever shown.
 *
 * @property int $id
 * @property string $name
 * @property string $pin
 */
final class BadgeEntity extends Model
{
    /** @var list<string> */
    protected $visible = ['id', 'name'];
}
