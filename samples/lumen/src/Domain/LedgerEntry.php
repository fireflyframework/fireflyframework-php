<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Illuminate\Database\Eloquent\Model;

final class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';

    /** @var list<string> */
    protected $fillable = ['wallet_id', 'event_type', 'amount_minor', 'balance_minor', 'occurred_at'];
}
