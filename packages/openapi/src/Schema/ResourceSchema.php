<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * What Laravel's API resources decide about their own wire shape, read the way the resources read it.
 *
 * A JsonResource is a Responsable, so ResponseFactory asks it for its own response, and ResourceResponse
 * writes resolve() WRAPPED under the resource class's static `$wrap` — `data` unless the application called
 * `JsonResource::withoutWrapping()` or the class set its own. Nested inside another payload, the same resource
 * is resolve() alone (JsonResource::jsonSerialize()), which is why the envelope is applied by the response
 * that returns a resource and never baked into the resource's component.
 *
 * A ResourceCollection resolves to a list of the resource it COLLECTS, found as CollectsResources::collects()
 * finds it: the #[Collects] attribute, then the `$collects` property, then the naming convention —
 * `ParcelCollection` collects `Parcel` or `ParcelResource`, whichever exists. An anonymous collection's element
 * is set at runtime (`ParcelResource::collection($items)`) and is unknown to anything reading the class.
 */
final class ResourceSchema
{
    public static function isResource(string $class): bool
    {
        return is_a($class, JsonResource::class, true);
    }

    public static function isCollection(string $class): bool
    {
        return is_a($class, ResourceCollection::class, true);
    }

    /**
     * The resource class a collection collects, or null when only runtime knows.
     *
     * @param  ReflectionClass<object>  $collection
     */
    public static function collects(ReflectionClass $collection): ?string
    {
        $attribute = $collection->getAttributes(Collects::class)[0] ?? null;
        $declared = $collection->getDefaultProperties()['collects'] ?? null;
        $collects = null;

        if ($attribute !== null) {
            $collects = $attribute->newInstance()->class;
        } elseif (is_string($declared) && $declared !== '') {
            $collects = $declared;
        } elseif (str_ends_with($collection->getShortName(), 'Collection')) {
            foreach ([Str::replaceLast('Collection', '', $collection->getName()), Str::replaceLast('Collection', 'Resource', $collection->getName())] as $candidate) {
                if (class_exists($candidate)) {
                    $collects = $candidate;
                    break;
                }
            }
        }

        return $collects !== null && self::isResource($collects) ? $collects : null;
    }

    /**
     * The key a returned resource of this class is wrapped under, or null when it is sent unwrapped.
     */
    public static function wrap(string $class): ?string
    {
        if (! is_a($class, JsonResource::class, true)) {
            return null;
        }

        // Read off the class itself, at generation time, inside the booted application: that is the value
        // `JsonResource::withoutWrapping()` in a service provider has already changed, exactly as the response
        // will see it.
        $wrap = $class::$wrap;

        return is_string($wrap) && $wrap !== '' ? $wrap : null;
    }
}
