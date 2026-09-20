<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

/** The descriptor a test advice bakes into the proxy: one label per method. */
final readonly class AuditNote
{
    public function __construct(public string $label) {}

    /** @return array{label: string} */
    public function toArray(): array
    {
        return ['label' => $this->label];
    }

    /** @param array{label: string} $row */
    public static function fromArray(array $row): self
    {
        return new self($row['label']);
    }
}
