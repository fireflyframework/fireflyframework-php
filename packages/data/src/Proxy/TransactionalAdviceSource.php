<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Container\Attributes\Component;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\Isolation;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;

/**
 * The #[Transactional] advice: rows come from the same TransactionalScanner that builds the manifest, and the
 * literal is the `new TransactionalDescriptor(propagation: \...\Propagation::REQUIRED, ...)` form the proxy has
 * always baked (enum cases as constants, class lists as var_export'd strings) — a proxy compiled by this wave
 * reads exactly like one compiled before it.
 *
 * @phpstan-import-type TransactionalRow from TransactionalDescriptor
 */
#[Component]
final class TransactionalAdviceSource implements AdviceSource
{
    public function advice(): Advice
    {
        return Advice::transactional();
    }

    public function scan(array $psr4): array
    {
        $rows = [];
        foreach ((new TransactionalScanner)->scan($psr4)->all() as $class => $proxy) {
            $rows[$class] = $proxy['methods'];
        }

        return $rows;
    }

    public function render(array $row): string
    {
        /** @var TransactionalRow $row */
        $descriptor = TransactionalDescriptor::fromArray($row);

        $propagation = '\\'.Propagation::class.'::'.$descriptor->propagation->name;
        $isolation = '\\'.Isolation::class.'::'.$descriptor->isolation->name;

        return 'new \\'.TransactionalDescriptor::class.'('
            ."propagation: {$propagation}, "
            ."isolation: {$isolation}, "
            .'readOnly: '.($descriptor->readOnly ? 'true' : 'false').', '
            .'rollbackFor: '.$this->exportClassList($descriptor->rollbackFor).', '
            .'noRollbackFor: '.$this->exportClassList($descriptor->noRollbackFor).', '
            .'connection: '.($descriptor->connection === null ? 'null' : var_export($descriptor->connection, true)).', '
            .'timeout: '.($descriptor->timeout === null ? 'null' : (string) $descriptor->timeout)
            .')';
    }

    /**
     * @param  list<class-string<\Throwable>>  $classes
     */
    private function exportClassList(array $classes): string
    {
        return '['.implode(', ', array_map(static fn (string $class): string => var_export($class, true), $classes)).']';
    }
}
