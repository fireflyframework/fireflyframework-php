<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;

/**
 * A second advice kind, ordered BEFORE the transactional one (100 < 1000), that claims every public method of
 * ChainedLedger — including peek(), which carries no #[Transactional]. Its "scan" is a literal table, because
 * the point of the fixture is the chain, not a scanner.
 *
 * A #[Component] so a boot that scans this directory collects it through Container::getAll(AdviceSource::class)
 * exactly as firefly/security's source will be collected. It declares itself INERT when its interceptor is
 * unbound — the shape Security's advice takes, whose interceptor bean exists only under the master flag — so
 * ProxyChainInertBootTest can prove the pass-through path through the real pipeline; the registry's fail-loud
 * default is pinned by InterceptorRegistryTest with an advice that does not opt in.
 */
#[Component]
final class AuditAdviceSource implements AdviceSource
{
    public function advice(): Advice
    {
        return new Advice('audit', AuditInterceptor::class, AuditNote::class, 100, inertWhenUnbound: true);
    }

    public function scan(array $psr4): array
    {
        return [
            ChainedLedger::class => [
                'post' => ['label' => 'posting'],
                'peek' => ['label' => 'peeking'],
                'wipe' => ['label' => 'wiping'],
            ],
        ];
    }

    public function render(array $row): string
    {
        return '\\'.AuditNote::class.'::fromArray('.var_export($row, true).')';
    }
}
