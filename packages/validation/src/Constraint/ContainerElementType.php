<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Validation\Valid;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * The class every element of a list-typed member holds — the one thing PHP's `array` cannot say, and the
 * one thing three parts of the framework must agree on: ConstraintScanner (to cascade #[Valid] into each
 * element), RouteScanner (to hydrate each element as that class) and the OpenAPI generator's reflective
 * fallback (to document `items`). Before this class each of them read the docblock privately; a cascade
 * that validated `lines[0].sku` against a class the hydrator did not know would have passed a body
 * validation accepted and hydration then refused as a 400 — exactly the split one resolver prevents. All
 * three now ask here, and the two private copies are gone.
 *
 * THREE SOURCES, IN ORDER OF HOW EXPLICIT THEY ARE:
 *   1. `#[Valid(each: Line::class)]` — the author said it in code (Bean Validation's `List<@Valid Line>`);
 *   2. a `@var list<Line>` docblock on the member itself (a promoted parameter's doc comment is the
 *      property's, so it may sit on the constructor parameter);
 *   3. the constructor's `@param list<Line> $lines` tag.
 *
 * `list<X>`, `array<int, X>`, `array<K, X>`, `iterable<X>`, `non-empty-list<X>`, `non-empty-array<X>` and
 * `X[]` all mean the same list, with or without a leading `?` or a trailing `|null`. A name written short is
 * resolved the way PHP would resolve it: already qualified, then the declaring class's own namespace, then
 * the file's `use` imports (read from the source, because reflection does not expose them; memoised per
 * file). A name that resolves to no loadable class answers null — and so does a nested list
 * (`list<list<X>>`), whose inner generic the pattern refuses on purpose: the element of a nested list is a
 * list, not a class — and so does a scalar element. Only `each:` throws, because a class named in code that
 * does not exist is a typo, not an omission. Whether null is a refusal or a shrug is the CALLER's decision:
 * the constraint scanner refuses (a #[Valid] that cannot cascade is a mistake), the hydrator and the
 * generator leave the member alone (an untyped array is a legal payload).
 *
 * Compile-time only: it runs inside the three scanners, never on a request.
 */
final class ContainerElementType
{
    private const string GENERIC = '\??(?:non-empty-list|non-empty-array|list|array|iterable)<(?:[^,<>]+,\s*)?([^<>]+)>(?:\|null)?';

    private const string SUFFIX = '\??([\w\\\\]+)\[\](?:\|null)?';

    /** @var array<string, array<string, string>> file path => alias => FQCN */
    private static array $imports = [];

    public static function of(ReflectionParameter|ReflectionProperty $member): ?string
    {
        foreach ($member->getAttributes(Valid::class) as $attribute) {
            $each = $attribute->newInstance()->each;
            if ($each === null) {
                continue;
            }

            if (! class_exists($each)) {
                throw new ConfigurationException(sprintf(
                    '#[Valid(each: %s)] on %s names a class that does not exist.',
                    $each,
                    self::describe($member),
                ));
            }

            return $each;
        }

        $declaring = $member->getDeclaringClass();
        if ($declaring === null) {
            return null;
        }

        $property = $member instanceof ReflectionProperty ? $member : self::promoted($member, $declaring);
        if ($property !== null) {
            $own = self::fromVar($property->getDocComment() ?: '', $declaring);
            if ($own !== null) {
                return $own;
            }
        }

        if ($member instanceof ReflectionParameter) {
            return self::fromParams($member->getDeclaringFunction()->getDocComment() ?: '', $declaring)[$member->getName()] ?? null;
        }

        return null;
    }

    /**
     * Element classes read out of a constructor docblock: `@param list<Line> $lines`, `@param Line[] $lines`
     * and `@param array<int, Line> $lines` all mean the same thing. A name that does not resolve is left out.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, string> parameter name => element class
     */
    public static function fromParams(string $docComment, ReflectionClass $declaring): array
    {
        if ($docComment === '') {
            return [];
        }

        $types = [];
        foreach (['/@param\s+'.self::GENERIC.'\s+\$(\w+)/', '/@param\s+'.self::SUFFIX.'\s+\$(\w+)/'] as $pattern) {
            if (preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $resolved = self::resolve(trim($match[1]), $declaring);
                if ($resolved !== null) {
                    $types[$match[2]] = $resolved;
                }
            }
        }

        return $types;
    }

    /**
     * The element class a member's own `@var` tag states, or null.
     *
     * @param  ReflectionClass<object>  $declaring
     */
    public static function fromVar(string $docComment, ReflectionClass $declaring): ?string
    {
        if ($docComment === '') {
            return null;
        }

        foreach (['/@var\s+'.self::GENERIC.'/', '/@var\s+'.self::SUFFIX.'/'] as $pattern) {
            if (preg_match($pattern, $docComment, $match) === 1) {
                return self::resolve(trim($match[1]), $declaring);
            }
        }

        return null;
    }

    /**
     * @param  ReflectionClass<object>  $declaring
     */
    private static function promoted(ReflectionParameter $parameter, ReflectionClass $declaring): ?ReflectionProperty
    {
        if (! $parameter->isPromoted() || ! $declaring->hasProperty($parameter->getName())) {
            return null;
        }

        return $declaring->getProperty($parameter->getName());
    }

    /**
     * @param  ReflectionClass<object>  $declaring
     */
    private static function resolve(string $name, ReflectionClass $declaring): ?string
    {
        $name = ltrim($name, '\\');
        if (class_exists($name)) {
            return $name;
        }

        $namespace = $declaring->getNamespaceName();
        if ($namespace !== '' && class_exists($candidate = $namespace.'\\'.$name)) {
            return $candidate;
        }

        foreach (self::imports($declaring) as $alias => $fqcn) {
            if ($alias === $name && class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * The file's `use` imports, alias => FQCN. Read from the source because reflection does not expose them;
     * memoised because a DTO asks once per member and the file does not change under a scan.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, string>
     */
    private static function imports(ReflectionClass $declaring): array
    {
        $file = $declaring->getFileName();
        if ($file === false || ! is_file($file)) {
            return [];
        }

        if (isset(self::$imports[$file])) {
            return self::$imports[$file];
        }

        $source = (string) file_get_contents($file);
        if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $matches, PREG_SET_ORDER) === false) {
            return self::$imports[$file] = [];
        }

        $imports = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? '';
            if ($alias === '') {
                $parts = explode('\\', $fqcn);
                $alias = end($parts);
            }
            $imports[$alias] = $fqcn;
        }

        return self::$imports[$file] = $imports;
    }

    private static function describe(ReflectionParameter|ReflectionProperty $member): string
    {
        $declaring = $member->getDeclaringClass();

        return ($declaring === null ? '' : $declaring->getName().'::').'$'.$member->getName();
    }
}
