<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Proxy;

use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * The child half of the state-copy fixture (see TxLedgerBase): its own private `$label` shadows the parent's by
 * name only — they are two slots — and its one #[Transactional] method reads the parent's private through the
 * parent's accessor, which is exactly how a proxied #[Repository] reaches EloquentRepository's translator.
 * NOT `final` — the proxy extends it.
 */
class TxLedger extends TxLedgerBase
{
    private string $label = 'child';

    public function childLabel(): string
    {
        return $this->label;
    }

    #[Transactional]
    public function reveal(): string
    {
        return $this->secret().'/'.$this->manifest().'/'.$this->baseLabel().'/'.$this->childLabel();
    }
}
