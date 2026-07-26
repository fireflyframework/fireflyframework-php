<?php

declare(strict_types=1);

namespace Firefly\Actuator\Introspection;

/**
 * A boot-time snapshot of the condition-filtered BeanDefinitionRegistry, bound as a singleton so BeansEndpoint can
 * read it at REQUEST time (BootContext is deliberately never container-bound). Built by ActuatorRouteRegistrar from
 * $context->definitions->all().
 */
final readonly class BeansCatalog
{
    /**
     * @param  list<array{class: string, stereotype: string, scope: string, name: string|null, interfaces: list<string>, beans: list<string>}>  $beans
     */
    public function __construct(public array $beans) {}

    /**
     * @return list<array{class: string, stereotype: string, scope: string, name: string|null, interfaces: list<string>, beans: list<string>}>
     */
    public function all(): array
    {
        return $this->beans;
    }
}
