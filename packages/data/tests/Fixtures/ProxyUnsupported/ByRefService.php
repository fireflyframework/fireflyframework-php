<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\ProxyUnsupported;

use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * A #[Transactional] method with a by-reference parameter (`&$out`). The generated proxy would wrap the call in
 * `fn () => parent::collect($out)`, an arrow closure that captures $out BY VALUE — silently dropping the
 * writeback — so scanProxyMethods() must FAIL LOUD at scan time. Isolated in its OWN directory so scanning it
 * does not poison the other proxy fixtures (a shared dir would make every scan throw). NOT `final`.
 */
class ByRefService
{
    /**
     * @param  list<string>  $out
     */
    #[Transactional]
    public function collect(array &$out): void
    {
        $out[] = 'x';
    }
}
