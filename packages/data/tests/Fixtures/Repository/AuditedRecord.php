<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Repository;

use Firefly\Data\Repository\Auditing\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property int|string|null $created_by
 * @property int|string|null $updated_by
 */
final class AuditedRecord extends Model
{
    use Auditable;

    protected $table = 'audited_records';

    public $timestamps = false;

    protected $guarded = [];
}
