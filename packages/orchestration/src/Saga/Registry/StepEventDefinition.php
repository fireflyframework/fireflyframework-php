<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Registry;

use Firefly\Orchestration\Core\Rows;

/** A compiled #[StepEvent] (or #[TccEvent]): '' means "use the default". `key` names an input key. */
final readonly class StepEventDefinition
{
    public function __construct(
        public string $topic,
        public string $type,
        public string $key,
    ) {}

    /** @return array{topic: string, type: string, key: string} */
    public function toArray(): array
    {
        return ['topic' => $this->topic, 'type' => $this->type, 'key' => $this->key];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(Rows::string($row, 'topic'), Rows::string($row, 'type'), Rows::string($row, 'key'));
    }
}
