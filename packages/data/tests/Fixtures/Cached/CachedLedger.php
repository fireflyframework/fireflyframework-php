<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Cached;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * The bean of the cached-boot fixture (CachedBootBridgeTest): one #[Transactional] method, so today's
 * firefly:cache would compile a transactional.php row and a proxy for it. NOT `final` — the proxy extends it.
 * Used by no other test, so the shape of its loaded proxy class is always the compiled, transactional-only one.
 */
#[Service]
class CachedLedger
{
    /** @var list<string> */
    public array $entries = [];

    #[Transactional]
    public function post(string $entry): string
    {
        $this->entries[] = $entry;

        return 'posted:'.$entry;
    }

    public function peek(): string
    {
        return 'peek:'.count($this->entries);
    }
}
