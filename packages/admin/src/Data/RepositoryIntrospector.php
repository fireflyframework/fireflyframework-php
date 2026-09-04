<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use DateTimeInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * THE ONLY CLASS IN THIS LAYER THAT REFLECTS — everything else reads the compiled BeansCatalog, the
 * container, or Laravel's schema builder.
 *
 * WHY REFLECTION IS UNAVOIDABLE HERE, AND NOWHERE ELSE. Discovery answers "which beans are repositories"
 * from BeansCatalog rows, which already carry `class`, `stereotype` and the full `interfaces` list that
 * ComponentScanner recorded at scan time — no runtime introspection needed, and the framework's
 * reflection-free boot contract is preserved. But two facts the browser cannot work without are simply not in
 * any manifest:
 *
 *   1. WHICH MODEL a repository manages. `EloquentRepository` declares `protected string $model` and the
 *      concrete repository sets it as a property DEFAULT. It is protected, there is no accessor, and the
 *      value never reaches a descriptor — ComponentScanner records a class's dependencies and interfaces, not
 *      its property initialisers. Without it there is no table, no schema, no key name, and therefore no
 *      browser. It is read via `ReflectionClass::getDefaultProperties()`, which does NOT construct the
 *      repository: discovery must stay cheap and must not be able to fail because a repository constructor
 *      wanted a live connection.
 *
 *   2. WHAT SHAPE a non-Eloquent entity has. A plain `CrudRepository` over value objects has no table to ask,
 *      so the only honest column list is the entity's own declared fields — public properties and promoted
 *      constructor parameters. Promoted parameters matter most and are the reason accessibility has to be
 *      bypassed: `Firefly\Domain\Entity` promotes `protected int|string|null $id`, so a scan restricted to
 *      public properties would miss the identifier of every entity in the framework's own DDD base class.
 *
 * Confining both to one class is what keeps the rest of the layer honest: DataResourceRegistry,
 * DataSchemaFactory, DataQueryEngine and DataBrowser contain no `Reflection*` reference at all, so the cost
 * and the risk are auditable by grep. Every entry point is guarded and memoised — reflection on a class the
 * autoloader cannot complete throws, and a browsable-resource list that dies because one repository is broken
 * is useless, so a failure degrades that one resource instead.
 */
final class RepositoryIntrospector
{
    /** @var array<class-string, class-string|null> */
    private array $models = [];

    /** @var array<class-string, class-string|null> */
    private array $entities = [];

    /** @var array<class-string, list<array{name: string, type: string, nullable: bool}>> */
    private array $fields = [];

    /**
     * The `$model` class-string an EloquentRepository subclass declares, or null when there is none.
     *
     * Read from the class's default property values, never from an instance: `getDefaultProperties()` reports
     * a protected property's initialiser without running the constructor, so this is safe to call for every
     * discovered repository during a menu render. The abstract base's own `protected string $model;` has no
     * initialiser and is therefore absent from the result — exactly the desired outcome, since the base
     * manages nothing.
     *
     * @param  class-string  $repositoryClass
     * @return class-string|null
     */
    public function modelOf(string $repositoryClass): ?string
    {
        if (array_key_exists($repositoryClass, $this->models)) {
            return $this->models[$repositoryClass];
        }

        return $this->models[$repositoryClass] = $this->readModel($repositoryClass);
    }

    /**
     * @param  class-string  $repositoryClass
     * @return class-string|null
     */
    private function readModel(string $repositoryClass): ?string
    {
        try {
            $reflection = new ReflectionClass($repositoryClass);

            if (! $reflection->hasProperty('model') || $reflection->getProperty('model')->isStatic()) {
                return null;
            }

            $model = $reflection->getDefaultProperties()['model'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($model) && class_exists($model) ? $model : null;
    }

    /**
     * The entity class a non-Eloquent repository manages, inferred from the RETURN TYPE it declares.
     *
     * A repository that means to be browsable narrows `findById(): ?Wallet` (the lumen sample does exactly
     * this, and PHP's covariant-return rule is what makes it possible while the parameter stays `mixed`).
     * `save()` is consulted as a second source because a repository may narrow one and not the other. The
     * base signatures return `?object` / `object`, which carries no information and is rejected, so this
     * never reports a bogus entity — it reports null and the resource falls back to a schema-less listing.
     *
     * @param  class-string  $repositoryClass
     * @return class-string|null
     */
    public function entityOf(string $repositoryClass): ?string
    {
        if (array_key_exists($repositoryClass, $this->entities)) {
            return $this->entities[$repositoryClass];
        }

        return $this->entities[$repositoryClass] = $this->readEntity($repositoryClass);
    }

    /**
     * @param  class-string  $repositoryClass
     * @return class-string|null
     */
    private function readEntity(string $repositoryClass): ?string
    {
        $reflection = new ReflectionClass($repositoryClass);

        foreach (['findById', 'save'] as $method) {
            if (! $reflection->hasMethod($method)) {
                continue;
            }

            $entity = $this->classFromReturnType($reflection->getMethod($method));
            if ($entity !== null) {
                return $entity;
            }
        }

        return null;
    }

    /** @return class-string|null */
    private function classFromReturnType(ReflectionMethod $method): ?string
    {
        $type = $method->getReturnType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        // `object`, `static` and `self` are the uninformative answers the base class already gives.
        return $name !== 'object' && $name !== 'static' && $name !== 'self' && class_exists($name) ? $name : null;
    }

    /**
     * The declared fields of a plain entity, in declaration order: promoted constructor parameters first
     * (that is the order the author wrote the record in, and the closest thing a PHP class has to a column
     * order), then any remaining public properties.
     *
     * Promoted parameters are included at EVERY visibility while plain properties are included only when
     * public. That asymmetry is deliberate: a promoted parameter is part of the type's published construction
     * contract — you cannot build the object without supplying it — so it is a field of the record whatever
     * its visibility, whereas a private non-promoted property is genuine internal state a browser has no
     * business rendering.
     *
     * @param  class-string  $entityClass
     * @return list<array{name: string, type: string, nullable: bool}>
     */
    public function fieldsOf(string $entityClass): array
    {
        if (isset($this->fields[$entityClass])) {
            return $this->fields[$entityClass];
        }

        $reflection = new ReflectionClass($entityClass);

        /** @var array<string, array{name: string, type: string, nullable: bool}> $fields */
        $fields = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isPromoted()) {
                $fields[$parameter->getName()] = $this->describe($parameter->getName(), $parameter->getType());
            }
        }

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || isset($fields[$property->getName()])) {
                continue;
            }

            $fields[$property->getName()] = $this->describe($property->getName(), $property->getType());
        }

        return $this->fields[$entityClass] = array_values($fields);
    }

    /**
     * Read one field off an entity instance, tolerating both shapes an entity comes in: a promoted property
     * at any visibility, and a class that exposes only accessors.
     *
     * `ReflectionProperty::getValue()` ignores accessibility from PHP 8.1 onward (setAccessible() became a
     * no-op), so no mutation of the reflection object is needed — nothing here can leave a property
     * permanently accessible to anything else. An uninitialised typed property reads as null rather than
     * throwing, because "not yet set" is a real state of a hydrated-from-nothing entity and a listing must
     * render it as an empty cell, not as a 500.
     */
    public function read(object $entity, string $field): mixed
    {
        try {
            $reflection = new ReflectionObject($entity);

            if ($reflection->hasProperty($field)) {
                $property = $reflection->getProperty($field);

                return $property->isStatic() || ! $property->isInitialized($entity) ? null : $property->getValue($entity);
            }

            foreach ([$field, 'get'.ucfirst($field), 'is'.ucfirst($field)] as $candidate) {
                if ($reflection->hasMethod($candidate)) {
                    $method = $reflection->getMethod($candidate);
                    if ($method->isPublic() && ! $method->isStatic() && $method->getNumberOfRequiredParameters() === 0) {
                        return $method->invoke($entity);
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Map a declared PHP type onto the closed display vocabulary. A union or intersection has no single
     * display type — `int|string|null` is the framework's own identifier type — so it degrades to `string`,
     * which renders every member correctly and formats none of them wrongly.
     *
     * @return array{name: string, type: string, nullable: bool}
     */
    private function describe(string $name, ?ReflectionType $type): array
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return ['name' => $name, 'type' => DataColumn::TYPE_STRING, 'nullable' => $type->allowsNull()];
        }

        if (! $type instanceof ReflectionNamedType) {
            return ['name' => $name, 'type' => DataColumn::TYPE_STRING, 'nullable' => true];
        }

        return ['name' => $name, 'type' => $this->displayType($type), 'nullable' => $type->allowsNull()];
    }

    private function displayType(ReflectionNamedType $type): string
    {
        if ($type->isBuiltin()) {
            return match ($type->getName()) {
                'int' => DataColumn::TYPE_INT,
                'bool' => DataColumn::TYPE_BOOL,
                'array', 'iterable' => DataColumn::TYPE_JSON,
                default => DataColumn::TYPE_STRING,
            };
        }

        $name = $type->getName();

        return is_a($name, DateTimeInterface::class, true) ? DataColumn::TYPE_DATETIME : DataColumn::TYPE_STRING;
    }
}
