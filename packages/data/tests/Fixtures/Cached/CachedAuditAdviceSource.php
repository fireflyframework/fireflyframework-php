<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Cached;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Data\Tests\Fixtures\Chain\AuditNote;

/**
 * The discriminator of the cached-boot fixture: a second AdviceSource that claims CachedLedger. It is a real
 * #[Component], so a boot that took the in-process SCAN branch would collect it through Container::getAll(),
 * plan CachedLedger for ['audit', 'tx'] and materialise a two-advice proxy in a temp directory. A boot that
 * honours the compiled transactional.php never consults it, plans ['tx'] alone and loads the compiled proxy
 * from the cache directory's classmap — which is what CachedBootBridgeTest asserts.
 */
#[Component]
final class CachedAuditAdviceSource implements AdviceSource
{
    public function advice(): Advice
    {
        return new Advice('audit', AuditInterceptor::class, AuditNote::class, 100, inertWhenUnbound: true);
    }

    public function scan(array $psr4): array
    {
        return [CachedLedger::class => ['post' => ['label' => 'posting'], 'peek' => ['label' => 'peeking']]];
    }

    public function render(array $row): string
    {
        return '\\'.AuditNote::class.'::fromArray('.var_export($row, true).')';
    }
}
