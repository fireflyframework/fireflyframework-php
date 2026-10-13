<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Firefly\Orchestration\Core\Argument\ParameterBinding;

/**
 * A public method on a container-resolved bean, with its compiled parameter plan. The bean is resolved per
 * call through the Illuminate container, so a #[Service] step bean is the fully post-processed one —
 * #[Transactional], #[PreAuthorize] and the resilience attributes apply to saga steps exactly as they do to
 * any other service method.
 */
final readonly class MethodInvocation implements Invocation
{
    /**
     * @param  list<ParameterBinding>  $parameters
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $parameters = [],
        public ?string $variable = null,
    ) {}

    public function describe(): string
    {
        return $this->class.'::'.$this->method;
    }

    public function setVariable(): ?string
    {
        return $this->variable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'parameters' => array_map(static fn (ParameterBinding $binding): array => $binding->toArray(), $this->parameters),
            'setVariable' => $this->variable,
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            Rows::string($row, 'class'),
            Rows::string($row, 'method'),
            array_map(static fn (array $binding): ParameterBinding => ParameterBinding::fromArray($binding), Rows::rows($row, 'parameters')),
            Rows::nullableString($row, 'setVariable'),
        );
    }
}
