<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $owner
 * @property int $balance
 */
final class Account extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $table = 'accounts';

    public $timestamps = false;

    protected $guarded = [];

    public function open(): void
    {
        $this->raiseEvent(new AccountOpened((string) $this->owner, (int) $this->balance));
    }
}
