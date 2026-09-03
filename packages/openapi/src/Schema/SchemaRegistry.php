<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Closure;

/**
 * The `components/schemas` map, and the one place a DTO is turned into a reusable `$ref`.
 *
 * TWO PROBLEMS THIS SOLVES, both of which a naive "inline the schema at every use site" generator has.
 *
 * (1) DUPLICATION. A DTO used by six operations would be emitted six times, and every generated client would
 * mint six structurally identical anonymous types with six different names. Registering once and referring by
 * `$ref` is what makes `openapi-generator`/`orval`/`kiota` produce ONE named type per DTO, which is the whole
 * point of generating the document in the first place.
 *
 * (2) RECURSION. `SelfReferential { #[Valid] ?SelfReferential $parent; }` cannot be inlined at all: the
 * expansion does not terminate. ref() therefore RESERVES the component name before invoking the builder, so
 * a nested call for the same class finds the name already taken and returns the reference immediately,
 * closing the cycle. This mirrors the ancestor-set guard ConstraintScanner uses for the same shape, with the
 * difference that a `$ref` cycle is legal and useful in a document where a flattened rule list would be
 * infinite.
 *
 * NAMES are the class's short name, because that is what a human reads in a viewer and what a generator turns
 * into a type name. Two DTOs sharing a short name across namespaces (`Order\Dto\Address` and
 * `Billing\Dto\Address`) would collide and one would silently overwrite the other, so the SECOND claimant of
 * a name falls back to its dotted fully-qualified name — ugly, unambiguous, and rare. First claimant wins so
 * that adding a second Address elsewhere in the app never renames the one that was already published.
 */
final class SchemaRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /** @var array<string, string> component name => the class that claimed it */
    private array $owners = [];

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return string the `$ref` pointer to this class's component schema
     */
    public function ref(string $class, Closure $build): string
    {
        $name = $this->nameFor($class);

        if (! array_key_exists($name, $this->schemas)) {
            // Reserve BEFORE building — see the class docblock's recursion note. The placeholder is only
            // ever observable from inside $build()'s own re-entrant call, which reads the NAME, not the body.
            $this->schemas[$name] = [];
            $this->schemas[$name] = $build();
        }

        return '#/components/schemas/'.$name;
    }

    /**
     * Register a schema under a fixed, framework-owned name (ProblemDetails). Distinct from ref() because
     * there is no class to derive a name from and no cycle to guard against.
     *
     * @param  array<string, mixed>  $schema
     */
    public function put(string $name, array $schema): void
    {
        $this->schemas[$name] = $schema;
    }

    /**
     * @return array<string, array<string, mixed>> sorted by component name, so a regenerated document is
     *                                             byte-identical to the previous one and diffs usefully in
     *                                             review
     */
    public function all(): array
    {
        $schemas = $this->schemas;
        ksort($schemas);

        return $schemas;
    }

    private function nameFor(string $class): string
    {
        $short = str_contains($class, '\\') ? substr($class, strrpos($class, '\\') + 1) : $class;

        if (($this->owners[$short] ?? $class) === $class) {
            $this->owners[$short] = $class;

            return $short;
        }

        return str_replace('\\', '.', $class);
    }
}
