<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

/** A compiled exception-handler binding: which exception class a bean method handles, local or global. */
final readonly class ExceptionHandlerDescriptor
{
    public function __construct(
        public string $exceptionClass,
        public string $handlerClass,
        public string $methodName,
        public bool $global,
    ) {}

    /**
     * @return array{exceptionClass: string, handlerClass: string, methodName: string, global: bool}
     */
    public function toArray(): array
    {
        return [
            'exceptionClass' => $this->exceptionClass,
            'handlerClass' => $this->handlerClass,
            'methodName' => $this->methodName,
            'global' => $this->global,
        ];
    }

    /**
     * @param  array{exceptionClass: string, handlerClass: string, methodName: string, global: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['exceptionClass'], $data['handlerClass'], $data['methodName'], $data['global']);
    }
}
