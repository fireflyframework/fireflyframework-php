<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Engine;

/** What each participant's try receives (jafly TccInputs): its explicit input, else the global input. */
final readonly class TccInputs
{
    /**
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $values
     */
    private function __construct(
        private array $global,
        private array $values,
    ) {}

    /**
     * @param  array<string, mixed>  $global
     */
    public static function of(array $global = []): self
    {
        return new self($global, []);
    }

    public function forParticipant(string $participantId, mixed $input): self
    {
        return new self($this->global, [...$this->values, $participantId => $input]);
    }

    public function inputFor(string $participantId): mixed
    {
        return array_key_exists($participantId, $this->values) ? $this->values[$participantId] : $this->global;
    }

    /** @return array<string, mixed> */
    public function global(): array
    {
        return $this->global;
    }

    /** @return array<string, mixed> */
    public function explicit(): array
    {
        return $this->values;
    }
}
