<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * A crate row, described the way an IDE helper describes one.
 *
 * @property int $id
 * @property string $label the label printed on the crate
 * @property CrateStatus $status
 * @property string $secret
 * @property \DateTimeImmutable|null $shipped_at
 * @property-read int $parcel_count
 * @property-read string $display_name
 * @property-write string $password
 */
final class CrateEntity extends Model
{
    protected $table = 'crates';

    /** @var list<string> */
    protected $hidden = ['secret'];

    /** @var list<string> */
    protected $appends = ['display_name'];

    /** @return Attribute<non-falsy-string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => 'Crate '.$this->label);
    }
}
