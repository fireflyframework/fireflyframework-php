<?php

declare(strict_types=1);

namespace Firefly\Context\Scanner;

use Firefly\Context\Condition\ConditionAttribute;

/**
 * Everything ContextScanner discovers about one class: #[PostConstruct]/#[PreDestroy] method
 * names, #[AsEventListener] methods (event ALWAYS a resolved concrete class-string — inference off
 * the listener's first parameter type happens ONCE, at scan time, never at boot — see
 * ContextScanner::inferEventType()), and #[ConditionalOn*] attributes on the class itself and on
 * each #[Bean] method.
 *
 * Condition attributes are OBJECTS, and the compiled manifest is a plain var_export'd array loaded
 * with ZERO reflection — so every condition is stored here as a plain array shape
 * (['type' => class-string, 'args' => list<mixed>]), never as a live ConditionAttribute instance.
 * conditionInstances()/beanConditionInstances() reconstruct real instances on demand via
 * `new $type(...$args)` — plain object construction, NOT reflection, and safe to call at load
 * time or any time after.
 */
final readonly class ContextDescriptor
{
    /**
     * @param  list<string>  $postConstruct
     * @param  list<string>  $preDestroy
     * @param  list<array{method: string, event: string, order: int}>  $listeners
     * @param  list<array{type: string, args: list<mixed>}>  $conditions
     * @param  list<array{method: string, conditions: list<array{type: string, args: list<mixed>}>}>  $beanConditions
     */
    public function __construct(
        public string $class,
        public array $postConstruct = [],
        public array $preDestroy = [],
        public array $listeners = [],
        public array $conditions = [],
        public array $beanConditions = [],
    ) {}

    /**
     * Reconstructs the class-level #[ConditionalOn*] attributes via `new $type(...$args)`.
     *
     * @return list<ConditionAttribute>
     */
    public function conditionInstances(): array
    {
        return array_map(self::instantiate(...), $this->conditions);
    }

    /**
     * Reconstructs the #[ConditionalOn*] attributes declared on one #[Bean] method. Returns an
     * empty list both when the method has no conditions and when it isn't a #[Bean] method at all
     * — this is deliberately lenient, mirroring how a missing manifest entry elsewhere in Firefly
     * means "nothing to report", never an error.
     *
     * @return list<ConditionAttribute>
     */
    public function beanConditionInstances(string $method): array
    {
        foreach ($this->beanConditions as $entry) {
            if ($entry['method'] === $method) {
                return array_map(self::instantiate(...), $entry['conditions']);
            }
        }

        return [];
    }

    /**
     * @param  array{type: string, args: list<mixed>}  $entry
     */
    private static function instantiate(array $entry): ConditionAttribute
    {
        /** @var class-string<ConditionAttribute> $type */
        $type = $entry['type'];

        /** @var ConditionAttribute */
        return new $type(...$entry['args']);
    }

    /**
     * @return array{
     *     class: string,
     *     postConstruct: list<string>,
     *     preDestroy: list<string>,
     *     listeners: list<array{method: string, event: string, order: int}>,
     *     conditions: list<array{type: string, args: list<mixed>}>,
     *     beanConditions: list<array{method: string, conditions: list<array{type: string, args: list<mixed>}>}>,
     * }
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'postConstruct' => $this->postConstruct,
            'preDestroy' => $this->preDestroy,
            'listeners' => $this->listeners,
            'conditions' => $this->conditions,
            'beanConditions' => $this->beanConditions,
        ];
    }

    /**
     * @param array{
     *     class: string,
     *     postConstruct?: list<string>,
     *     preDestroy?: list<string>,
     *     listeners?: list<array{method: string, event: string, order: int}>,
     *     conditions?: list<array{type: string, args: list<mixed>}>,
     *     beanConditions?: list<array{method: string, conditions: list<array{type: string, args: list<mixed>}>}>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            class: $data['class'],
            postConstruct: $data['postConstruct'] ?? [],
            preDestroy: $data['preDestroy'] ?? [],
            listeners: $data['listeners'] ?? [],
            conditions: $data['conditions'] ?? [],
            beanConditions: $data['beanConditions'] ?? [],
        );
    }
}
