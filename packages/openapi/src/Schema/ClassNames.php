<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use ReflectionClass;

/**
 * Resolves a class name AS WRITTEN IN A DOCBLOCK to a fully-qualified one.
 *
 * A comment says `list<OrderLine>`, and `OrderLine` is whatever that name meant in the file it was written
 * in — a `use` alias, a class in the same namespace, or already fully qualified. Reflection does not expose a
 * file's imports, so the last case is answered by reading the source for its `use` statements, which is the
 * only way to resolve an alias at all.
 *
 * WHY IT IS ITS OWN CLASS. This resolution existed twice before it existed here: once in packages/web's
 * RouteScanner, where it compiles the hydration table ArgumentResolver binds against, and once mirrored into
 * ElementTypes so the generator could answer the same question for a class no route reaches. That mirror is
 * deliberate and documented — RouteScanner's copy is private to another package and cannot be called — but a
 * THIRD copy, for the return-type parser, would have been one too many: three implementations of one rule
 * drift, and the drift shows up as a document describing a shape the server would refuse to build. The
 * mirror is now factored out so the generator has one copy of it rather than one per consumer.
 *
 * The `imports()` read is memoised per file because a DTO graph asks about the same file once per member,
 * and re-reading and re-scanning a source file per member turned a twelve-member payload into twelve
 * identical file reads.
 */
final class ClassNames
{
    /** @var array<string, array<string, string>> file path => alias => FQCN */
    private static array $imports = [];

    /**
     * The class $name denotes when written inside $declaring, or null when it denotes no loadable class.
     *
     * Null rather than the unresolved string on purpose: every caller turns a resolved name into a `$ref`,
     * and a name that does not resolve must produce no `$ref` at all rather than a pointer into a component
     * that will never be registered. A dangling `$ref` breaks a viewer and every client generator; an absent
     * one merely describes the member as an untyped value, which is the truth.
     *
     * @param  ReflectionClass<object>|null  $declaring
     */
    public static function resolve(string $name, ?ReflectionClass $declaring): ?string
    {
        $name = ltrim(trim($name), '\\');

        if ($name === '') {
            return null;
        }

        if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
            return $name;
        }

        if ($declaring === null) {
            return null;
        }

        $namespace = $declaring->getNamespaceName();
        if ($namespace !== '') {
            $candidate = $namespace.'\\'.$name;
            if (class_exists($candidate) || interface_exists($candidate) || enum_exists($candidate)) {
                return $candidate;
            }
        }

        // An alias may be written for a nested name too — `Dto\Line` where `Dto` is the import — so the
        // first segment is what is matched and the rest is re-attached.
        $head = $name;
        $tail = '';
        if (($slash = strpos($name, '\\')) !== false) {
            $head = substr($name, 0, $slash);
            $tail = substr($name, $slash);
        }

        foreach (self::imports($declaring) as $alias => $fqcn) {
            if ($alias !== $head) {
                continue;
            }

            $candidate = $fqcn.$tail;
            if (class_exists($candidate) || interface_exists($candidate) || enum_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The file's `use` imports, alias => FQCN, read from the source because reflection does not expose them.
     *
     * Grouped (`use A\{B, C}`) and function/const imports are not handled: neither can name a class in a
     * type expression in any codebase this reads, and a partial regex that appeared to handle them would be
     * worse than one that visibly does not.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, string>
     */
    public static function imports(ReflectionClass $declaring): array
    {
        $file = $declaring->getFileName();

        if ($file === false || ! is_file($file)) {
            return [];
        }

        if (isset(self::$imports[$file])) {
            return self::$imports[$file];
        }

        $source = (string) file_get_contents($file);
        $imports = [];

        if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $fqcn = $match[1];
                $alias = $match[2] ?? '';

                if ($alias === '') {
                    $parts = explode('\\', $fqcn);
                    $alias = end($parts);
                }

                $imports[$alias] = $fqcn;
            }
        }

        return self::$imports[$file] = $imports;
    }
}
