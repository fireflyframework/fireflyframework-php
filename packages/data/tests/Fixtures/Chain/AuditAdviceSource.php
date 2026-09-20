<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;

/**
 * A second advice kind, ordered BEFORE the transactional one (100 < 1000), that claims every public method of
 * ChainedLedger — including peek(), which carries no #[Transactional]. Its "scan" is a literal table, because
 * the point of the fixture is the chain, not a scanner.
 */
final class AuditAdviceSource implements AdviceSource
{
    public function advice(): Advice
    {
        return new Advice('audit', AuditInterceptor::class, AuditNote::class, 100);
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
