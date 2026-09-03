<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;
use Illuminate\Contracts\Container\ContextualAttribute;

/**
 * Names the ONE bean a resolution should pick when the type alone is ambiguous.
 *
 * Three targets, two very different jobs:
 *
 *  - On a CLASS it is metadata: ComponentScanner records it on
 *    ComponentDescriptor::$qualifier and ContainerRegistrar::registerName() aliases
 *    the component under it, so `getByName('spanish')` works.
 *  - On a PARAMETER it is an injection instruction: "resolve this argument from the
 *    bean called $name, not from its declared type". This is the half that was
 *    DEAD. The attribute declared TARGET_PARAMETER from day one and absolutely
 *    nothing read it: `#[Qualifier('redisCache')] Cache $cache` silently received
 *    whatever Cache::class happened to resolve to, with no error and no warning —
 *    the worst possible failure mode, because the wrong dependency is injected and
 *    the application keeps running. It was caught by asking for a NON-primary bean
 *    by name and observing the #[Primary] one arrive instead.
 *
 * WHY IT IS A ContextualAttribute AND NOT A MANIFEST FIELD. A parameter qualifier
 * belongs to a constructor argument, not to a component, so it has no place on
 * ComponentDescriptor — and the compiled manifest deliberately describes COMPONENTS,
 * not their argument lists. The manifest is what makes DISCOVERY reflection-free at
 * runtime; it never claimed to make Illuminate's own container build reflection-free,
 * and it cannot: ContainerRegistrar binds `singleton($class, $class)` and lets
 * Illuminate autowire, which already reflects every constructor it builds. Illuminate
 * exposes exactly one seam on that existing reflection pass —
 * Container::whenHasAttribute() over parameter attributes implementing
 * ContextualAttribute — and #[Value] has ridden it since M2. Wiring #[Qualifier] the
 * same way costs one interface, adds ZERO reflection that was not already happening,
 * and leaves the compiled manifest shape untouched, so every manifest already on disk
 * keeps loading unchanged. The handler lives in
 * ContainerRegistrar::registerQualifierSupport().
 *
 * Because Illuminate reads contextual attributes in both Container::resolveDependencies()
 * and BoundMethod::addDependencyForCallParameter(), a qualifier works on #[Bean] factory
 * method parameters as well as on constructors.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class Qualifier implements ContextualAttribute
{
    public function __construct(public string $name) {}
}
