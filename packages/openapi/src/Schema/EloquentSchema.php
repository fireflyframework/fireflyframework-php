<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use BackedEnum;
use Closure;
use Firefly\OpenApi\Generator\DocBlock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The wire shape of an Eloquent model: what Model::toArray() writes, which is what JsonMessageConverter sends.
 *
 * WHY IT NEEDS ITS OWN READER. A model has no declared members worth reflecting — its columns are magic,
 * behind __get — and what it DOES declare publicly is Eloquent's own machinery. Reflecting it the way a DTO is
 * reflected documented `incrementing`, `exists`, `timestamps`, `wasRecentlyCreated`, `preventsLazyLoading` and
 * `usesUniqueIds`, all required, and not one column. That is the shape every LaraFly repository returns.
 *
 * TWO SOURCES, the richer first:
 *
 *   - `@property` tags — how an IDE helper describes a model, and what PHPStan reads. A `@property` is a
 *     column, present whenever the row is; a `@property-read` is an accessor or a relation, present only when
 *     appended or loaded; a `@property-write` is a mutator and is never serialised.
 *   - What Eloquent itself is told, read off an instance: the key, `$fillable`, the casts (`casts()` included),
 *     the timestamps and `$appends`. This is the skeleton's own OrderEntity style — no tags at all. It states
 *     no column's nullability, so every attribute but the key admits null; and an uncast column's JSON type is
 *     not stated anywhere, so it is left as any value rather than guessed.
 *
 * Either way relations are read off their DECLARED return type (`: HasMany`, with `HasMany<Line, $this>` for
 * the related class) — the same reading firefly/admin's data browser makes — under the snake_case key Laravel
 * serialises them with, and never required, since a relation is written only when it was loaded. `$hidden`
 * removes a member and a non-empty `$visible` keeps only what it lists, exactly as toArray() does.
 *
 * Instantiating the model to ask it is safe in the way it has to be: a Model's constructor takes an optional
 * attribute array and touches no connection. One that cannot be built (abstract, or a constructor with
 * demands) is documented from its tags alone.
 */
final class EloquentSchema
{
    /** The relations that serialise as a LIST of the related model; every other relation is one, or null. */
    private const array TO_MANY = [HasMany::class, BelongsToMany::class, MorphMany::class, HasManyThrough::class];

    public static function isModel(string $class): bool
    {
        return is_a($class, Model::class, true);
    }

    /**
     * @param  ReflectionClass<object>  $model
     * @param  Closure(string, list<array<string, mixed>>): array<string, mixed>  $resolve  a class in a type
     *                                                                                      expression to the
     *                                                                                      schema standing
     *                                                                                      for it
     * @return array<string, mixed>
     */
    public static function shape(ReflectionClass $model, Closure $resolve): array
    {
        $instance = self::instance($model);
        $appends = $instance?->getAppends() ?? [];

        $tagged = self::tagged($model, $resolve, $appends);
        if ($tagged !== null) {
            [$properties, $required] = $tagged;
        } elseif ($instance !== null) {
            [$properties, $required] = self::told($instance, $appends);
        } else {
            return ['type' => 'object'];
        }

        foreach ($appends as $name) {
            $properties[$name] ??= [];
            $required[] = $name;
        }

        foreach (self::relations($model, $resolve) as $name => $schema) {
            $properties[$name] ??= $schema;
        }

        $hidden = $instance?->getHidden() ?? [];
        $visible = $instance?->getVisible() ?? [];
        foreach (array_keys($properties) as $name) {
            if (in_array($name, $hidden, true) || ($visible !== [] && ! in_array($name, $visible, true))) {
                unset($properties[$name]);
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        $required = array_values(array_unique(array_filter($required, static fn (string $name): bool => isset($properties[$name]))));
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * The members the `@property` tags describe, or null when the model carries none. Read from the model and
     * every ancestor below Model, each tag in the scope of the class that wrote it.
     *
     * @param  ReflectionClass<object>  $model
     * @param  Closure(string, list<array<string, mixed>>): array<string, mixed>  $resolve
     * @param  array<array-key, string>  $appends
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}|null
     */
    private static function tagged(ReflectionClass $model, Closure $resolve, array $appends): ?array
    {
        $properties = [];
        $required = [];
        $found = false;

        for ($class = $model; $class !== false && $class->getName() !== Model::class; $class = $class->getParentClass()) {
            foreach (DocBlock::parse($class->getDocComment())->magicProperties() as $tag) {
                $found = true;

                if ($tag['access'] === 'write' || isset($properties[$tag['name']])) {
                    continue;
                }

                $schema = DocType::schema($tag['type'], $resolve, $class) ?? [];
                $properties[$tag['name']] = $tag['description'] === '' ? $schema : ['description' => $tag['description'], ...$schema];

                if ($tag['access'] === 'both' || in_array($tag['name'], $appends, true)) {
                    $required[] = $tag['name'];
                }
            }
        }

        return $found ? [$properties, $required] : null;
    }

    /**
     * The members Eloquent itself is told about: key, fillable, casts, timestamps.
     *
     * @param  array<array-key, string>  $appends
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private static function told(Model $instance, array $appends): array
    {
        $key = $instance->getKeyName();
        $casts = $instance->getCasts();

        $properties = [$key => $instance->getKeyType() === 'int' ? ['type' => 'integer'] : ['type' => 'string']];

        foreach ($instance->getFillable() as $name) {
            $properties[$name] ??= SchemaUnion::nullable(isset($casts[$name]) ? self::cast($casts[$name]) : []);
        }

        foreach ($casts as $name => $cast) {
            $properties[$name] ??= SchemaUnion::nullable(self::cast($cast));
        }

        if ($instance->usesTimestamps()) {
            foreach ([$instance->getCreatedAtColumn(), $instance->getUpdatedAtColumn()] as $column) {
                if (is_string($column)) {
                    $properties[$column] ??= SchemaUnion::nullable(['type' => 'string', 'format' => 'date-time']);
                }
            }
        }

        return [$properties, [$key]];
    }

    /**
     * What a cast writes into toArray(). Laravel writes a date through Carbon::toJSON() (RFC 3339), a custom
     * date format as the formatted string, a `timestamp` as Unix seconds and a `decimal` as a STRING — it keeps
     * its scale that way. Anything whose JSON type depends on the data (`array`, `json`, a custom cast class)
     * is any value.
     *
     * @return array<string, mixed>
     */
    private static function cast(string $cast): array
    {
        $type = $cast;
        $argument = null;
        if (($colon = strpos($cast, ':')) !== false) {
            $type = substr($cast, 0, $colon);
            $argument = substr($cast, $colon + 1);
        }

        if (is_subclass_of($cast, BackedEnum::class)) {
            return TypeSchema::for($cast) ?? [];
        }

        return match (strtolower($type)) {
            'int', 'integer', 'timestamp' => ['type' => 'integer'],
            'real', 'float', 'double' => ['type' => 'number'],
            'decimal', 'string', 'hashed' => ['type' => 'string'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'object' => ['type' => 'object'],
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime' => $argument === null
                ? ['type' => 'string', 'format' => 'date-time']
                : ['type' => 'string'],
            'encrypted' => $argument === null ? ['type' => 'string'] : self::cast($argument),
            default => [],
        };
    }

    /**
     * The model's relations, keyed as toArray() writes them.
     *
     * @param  ReflectionClass<object>  $model
     * @param  Closure(string, list<array<string, mixed>>): array<string, mixed>  $resolve
     * @return array<string, array<string, mixed>>
     */
    private static function relations(ReflectionClass $model, Closure $resolve): array
    {
        $snake = $model->getStaticPropertyValue('snakeAttributes', true) !== false;
        $relations = [];

        foreach ($model->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || ! $type instanceof ReflectionNamedType
                || ! is_a($type->getName(), Relation::class, true) || ! is_a($method->getDeclaringClass()->getName(), Model::class, true)
                || $method->getDeclaringClass()->getName() === Model::class) {
                continue;
            }

            $line = DocBlock::parse($method->getDocComment())->returnLine();
            $related = $line === null ? [] : (DocType::genericArguments($line, $resolve, $method->getDeclaringClass())[0] ?? []);

            $many = array_filter(self::TO_MANY, static fn (string $relation): bool => is_a($type->getName(), $relation, true)) !== [];
            $name = $snake ? Str::snake($method->getName()) : $method->getName();

            $relations[$name] = $many
                ? ($related === [] ? ['type' => 'array'] : ['type' => 'array', 'items' => $related])
                : SchemaUnion::nullable($related);
        }

        return $relations;
    }

    /** @param  ReflectionClass<object>  $model */
    private static function instance(ReflectionClass $model): ?Model
    {
        if (! $model->isInstantiable()) {
            return null;
        }

        try {
            $instance = $model->newInstance();
        } catch (Throwable) {
            return null;
        }

        return $instance instanceof Model ? $instance : null;
    }
}
