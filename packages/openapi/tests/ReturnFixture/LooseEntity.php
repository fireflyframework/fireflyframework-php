<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row described only by what Eloquent itself is told — the skeleton's OrderEntity style.
 */
final class LooseEntity extends Model
{
    protected $table = 'loose';

    /** @var list<string> */
    protected $fillable = ['label', 'secret', 'weight'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'status' => CrateStatus::class,
            'shipped_at' => 'datetime',
            'price' => 'decimal:2',
            'packed_on' => 'datetime:Y-m-d',
            'sealed_at' => 'timestamp',
        ];
    }

    /** @return HasMany<CrateEntity, $this> */
    public function crates(): HasMany
    {
        return $this->hasMany(CrateEntity::class);
    }

    /** @return BelongsTo<CrateEntity, $this> */
    public function parentCrate(): BelongsTo
    {
        return $this->belongsTo(CrateEntity::class);
    }
}
