<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Handler;

/**
 * A single compiled handler mapping: the message class it handles, the handler class + method, and whether it is a
 * command or query handler. Every field is scalar-or-enum-backed-string so the manifest var_exports as a plain array
 * literal (no closures/objects), loaded by require+map in production. Mirrors ScheduledDescriptor / EventListenerDescriptor.
 *
 * @phpstan-type HandlerRow array{messageClass: string, handlerClass: string, method: string, kind: string}
 */
final readonly class HandlerDescriptor
{
    public function __construct(
        public string $messageClass,
        public string $handlerClass,
        public string $method,
        public HandlerKind $kind,
    ) {}

    /**
     * @return HandlerRow
     */
    public function toArray(): array
    {
        return [
            'messageClass' => $this->messageClass,
            'handlerClass' => $this->handlerClass,
            'method' => $this->method,
            'kind' => $this->kind->value,
        ];
    }

    /**
     * @param  HandlerRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['messageClass'], $data['handlerClass'], $data['method'], HandlerKind::from($data['kind']));
    }
}
