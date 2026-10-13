<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * A ledger line that raises EntryPosted when it is posted; the after-commit dispatch carries it to the bridge.
 *
 * @property int $id
 * @property string $account
 * @property int $amount
 */
final class Entry extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $table = 'ledger_entries';

    public $timestamps = false;

    protected $guarded = [];

    /** The integer column reads back as a string on some PDO drivers; the handlers compare ints. */
    protected $casts = ['amount' => 'integer'];

    public function post(): void
    {
        $this->raiseEvent(new EntryPosted((string) $this->account, (int) $this->amount));
    }
}
