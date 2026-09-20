<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * The chain fixture: one #[Transactional] method (so the tx advice applies) and one plain method that only a
 * second, test-supplied advice claims. NOT `final` — the proxy extends it. A #[Service] so that a boot which
 * scans this directory (ProxyChainBootTest) registers it as a bean the TransactionalBeanPostProcessor wraps;
 * the unit-level ProxyChainTest instantiates it directly and never looks at the stereotype.
 */
#[Service]
class ChainedLedger
{
    /** @var list<string> */
    public array $entries = [];

    #[Transactional]
    public function post(string $entry, string $note = 'none'): string
    {
        $this->entries[] = $entry.'/'.$note;

        return 'posted:'.$entry;
    }

    public function peek(): string
    {
        return 'peek:'.count($this->entries);
    }

    #[Transactional]
    public function wipe(): void
    {
        $this->entries = [];
    }
}
