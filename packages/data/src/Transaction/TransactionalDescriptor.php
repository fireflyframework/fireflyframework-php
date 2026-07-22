<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Firefly\Data\Transaction\Attributes\Transactional;
use Throwable;

/**
 * The effective, per-method transaction settings — the runtime value the interceptor consumes and the pure-array
 * unit the manifest serialises. Built from a resolved #[Transactional] at scan time; baked into the generated
 * proxy as literals; reconstructed from the manifest row on the cached path.
 *
 * @phpstan-type TransactionalRow array{
 *     propagation: string,
 *     isolation: string,
 *     readOnly: bool,
 *     rollbackFor: list<class-string<\Throwable>>,
 *     noRollbackFor: list<class-string<\Throwable>>,
 *     connection: string|null,
 *     timeout: int|null,
 * }
 */
final readonly class TransactionalDescriptor
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

    public static function fromAttribute(Transactional $attribute): self
    {
        return new self(
            $attribute->propagation,
            $attribute->isolation,
            $attribute->readOnly,
            $attribute->rollbackFor,
            $attribute->noRollbackFor,
            $attribute->connection,
            $attribute->timeout,
        );
    }

    /**
     * @return TransactionalRow
     */
    public function toArray(): array
    {
        return [
            'propagation' => $this->propagation->name,
            'isolation' => $this->isolation->value,
            'readOnly' => $this->readOnly,
            'rollbackFor' => $this->rollbackFor,
            'noRollbackFor' => $this->noRollbackFor,
            'connection' => $this->connection,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @param  TransactionalRow  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Propagation::fromName($row['propagation']),
            Isolation::from($row['isolation']),
            $row['readOnly'],
            $row['rollbackFor'],
            $row['noRollbackFor'],
            $row['connection'],
            $row['timeout'],
        );
    }
}
