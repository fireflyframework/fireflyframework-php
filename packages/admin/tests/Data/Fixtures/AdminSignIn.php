<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A RESOURCE WHOSE COLUMN NAMES ARE LONGER THAN ITS VALUES, which is the ordinary shape of an audit or
 * sign-in table and the one case a hand-written dashboard listing never has.
 *
 * Every other fixture here is named the way a test author names things — `amount`, `active`, `meta` — and
 * that is exactly why the data browser could size a rigid column from its TYPE alone without a test
 * noticing. `DataColumn::label()` humanises the column name, so `failed_login_attempts` draws
 * `Failed login attempts`: twenty-one characters of 10px uppercase header over a column sized for a
 * five-figure count, cut mid-glyph by `table.ftable td,th{overflow:hidden}` with nothing on the page saying
 * it was cut. The values are two digits and a timestamp; the header is the thing that does not fit.
 *
 * @property int $id
 * @property string $email
 * @property int $failed_login_attempts
 * @property string|null $last_signed_in_at
 */
final class AdminSignIn extends Model
{
    protected $table = 'admin_sign_ins';

    public $timestamps = false;

    protected $guarded = [];
}
