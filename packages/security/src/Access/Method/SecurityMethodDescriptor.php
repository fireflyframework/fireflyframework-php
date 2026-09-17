<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

/**
 * One compiled method-security rule: the guarded class + method, the normalised boolean expression the evaluator
 * runs, the ordered parameter names (so #param references bind positional call args by name at enforcement),
 * and — when the attribute named them — the product code and sentence a refusal carries. Every field is
 * scalar/array so the manifest var_exports as a plain array literal loaded by require+map.
 *
 * `code`/`message` are optional in the ROW SHAPE, not only in the constructor: a manifest compiled before they
 * existed carries neither key, and fromArray() reads them with a null default so `firefly:cache` output from
 * the previous release still loads. toArray() always writes them, so a freshly compiled manifest is explicit.
 *
 * @phpstan-type SecurityMethodRow array{class: string, method: string, expression: string, params: list<string>, code?: string|null, message?: string|null}
 */
final readonly class SecurityMethodDescriptor
{
    /**
     * @param  list<string>  $params
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $expression,
        public array $params,
        public ?string $code = null,
        public ?string $message = null,
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->method;
    }

    /**
     * @return SecurityMethodRow
     */
    public function toArray(): array
    {
        return ['class' => $this->class, 'method' => $this->method, 'expression' => $this->expression, 'params' => $this->params, 'code' => $this->code, 'message' => $this->message];
    }

    /**
     * @param  SecurityMethodRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['class'], $data['method'], $data['expression'], $data['params'], $data['code'] ?? null, $data['message'] ?? null);
    }
}
