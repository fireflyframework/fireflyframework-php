<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

/**
 * One compiled method-security rule: the guarded class + method, the normalised PRE-invocation expression the
 * evaluator runs, the ordered parameter names (so #param references bind positional call args by name at
 * enforcement), the product code and sentence a refusal carries when the attribute named them — and, since
 * method security learned to look at results, the POST-invocation expression (#[PostAuthorize], with its own
 * code/sentence), the pre-filter expression and the parameter it narrows (#[PreFilter]) and the post-filter
 * expression (#[PostFilter]). Every field is scalar/array so the manifest var_exports as a plain array literal
 * loaded by require+map.
 *
 * A method with only post/filter rules compiles `expression` as `permitAll()`: every consumer that only ever
 * looked at the pre rule keeps working without a null check, and evaluating permitAll() costs nothing.
 *
 * Every optional key is optional in the ROW SHAPE, not only in the constructor: a manifest compiled before a
 * key existed carries no such key, and fromArray() reads each with a null default so `firefly:cache` output
 * from the previous release still loads. toArray() always writes them all, so a freshly compiled manifest is
 * explicit.
 *
 * @phpstan-type SecurityMethodRow array{class: string, method: string, expression: string, params: list<string>, code?: string|null, message?: string|null, postExpression?: string|null, postCode?: string|null, postMessage?: string|null, preFilter?: string|null, preFilterTarget?: string|null, postFilter?: string|null}
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
        public ?string $postExpression = null,
        public ?string $postCode = null,
        public ?string $postMessage = null,
        public ?string $preFilter = null,
        public ?string $preFilterTarget = null,
        public ?string $postFilter = null,
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
        return [
            'class' => $this->class,
            'method' => $this->method,
            'expression' => $this->expression,
            'params' => $this->params,
            'code' => $this->code,
            'message' => $this->message,
            'postExpression' => $this->postExpression,
            'postCode' => $this->postCode,
            'postMessage' => $this->postMessage,
            'preFilter' => $this->preFilter,
            'preFilterTarget' => $this->preFilterTarget,
            'postFilter' => $this->postFilter,
        ];
    }

    /**
     * @param  SecurityMethodRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['method'],
            $data['expression'],
            $data['params'],
            $data['code'] ?? null,
            $data['message'] ?? null,
            $data['postExpression'] ?? null,
            $data['postCode'] ?? null,
            $data['postMessage'] ?? null,
            $data['preFilter'] ?? null,
            $data['preFilterTarget'] ?? null,
            $data['postFilter'] ?? null,
        );
    }
}
