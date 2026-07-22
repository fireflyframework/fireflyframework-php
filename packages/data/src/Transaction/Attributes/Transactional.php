<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Attributes;

use Attribute;
use Firefly\Data\Transaction\Isolation;
use Firefly\Data\Transaction\Propagation;
use Throwable;

/**
 * Declarative transaction demarcation. On a class it is the default for every public method; on a method it
 * REPLACES the class-level settings for that method (Spring semantics). The compile-time TransactionalScanner
 * resolves the effective attribute per method into a TransactionalManifest; a generated proxy subclass then
 * wraps each transactional method in the TransactionInterceptor. Default rollbackFor=[Throwable] (PHP has no
 * checked/unchecked split, so any throwable rolls back unless noRollbackFor overrides — noRollbackFor wins).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Transactional
{
    /**
     * @param  list<class-string<Throwable>>  $rollbackFor
     * @param  list<class-string<Throwable>>  $noRollbackFor
     */
    public function __construct(
        public Propagation $propagation = Propagation::REQUIRED,
        public Isolation $isolation = Isolation::DEFAULT,
        public bool $readOnly = false,
        public array $rollbackFor = [Throwable::class],
        public array $noRollbackFor = [],
        public ?string $connection = null,
        public ?int $timeout = null,
    ) {}
}
