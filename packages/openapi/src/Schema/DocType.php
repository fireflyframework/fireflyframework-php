<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Schema;

use Closure;
use ReflectionClass;

/**
 * Compiles a PHPDoc TYPE EXPRESSION into a JSON Schema fragment.
 *
 * WHAT IT BUYS. A controller action's declared PHP return type is `array`, and `array` says nothing: every
 * success response in a generated document was `{"type": "object"}` — a body with no members, which a viewer
 * renders as a blank panel and a client generator turns into `any`. The shape was never missing, it was
 * written one line above the method:
 *
 * @return array{page: int, size: int, total: int, items: list<Order>}
 *
 * and the same is true of the members inside a returned object (`@var list<OrderLine> $lines`). PHP's type
 * system cannot express either; PHPDoc's can, and every one of these codebases already writes it because
 * PHPStan at level max requires it. So the type expression is not a comment to be trusted on faith — it is
 * a statement the static analyser already enforces against the code, which is precisely what makes it safe
 * to publish.
 *
 * WHY A PARSER AND NOT MORE REGULAR EXPRESSIONS. The generator already had two regexes for the one shape it
 * handled (`list<X>` and `X[]`), and they cannot be extended to the rest: `array{items: list<array<string,
 * Order>>}` needs balanced `<>` and `{}` and a comma that is only a separator at the outer level. That is a
 * grammar, and a grammar wants a recursive-descent parser — about two hundred lines here, against the four
 * transitive dependencies phpstan/phpdoc-parser would add to every application that installs this package
 * (the same trade DocBlock's docblock explains for prose).
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not verify that the expression matches the code — PHPStan does
 * that, and doing it again here would be a second, weaker implementation of a job already done. And an
 * expression it cannot make anything of yields NULL rather than a guess, so every caller falls back to the
 * declared PHP type instead of publishing a shape derived from a misread comment.
 *
 * GENERICS OVER A CLASS ARE NOT RESOLVED HERE, but they are no longer dropped either. `Page<Order>` hands the
 * class AND its argument schemas to the caller's resolver, which is what decides that a Laravel Collection is
 * a list and that a Page instantiation is a component of its own. It used to pass the class alone, so every
 * `Page<X>` in a codebase documented as one `Page` whose items were anything — see ResponseSchemaFactory.
 * The inverse direction is here: inside a generic class, its template parameters (`T`) resolve to whatever
 * the caller bound them to.
 *
 * NULL VERSUS THE EMPTY SCHEMA is the distinction the whole class turns on. `[]` is JSON Schema's "any
 * value", a real answer that `mixed` genuinely deserves. `null` here means "this expression told me
 * nothing" — an unresolvable class, a `callable`, a syntax error — and is the signal for a caller to use
 * what it knew before it asked.
 */
final class DocType
{
    private int $at = 0;

    /**
     * @param  Closure(string, list<array<string, mixed>>): (array<string, mixed>|null)  $schemaForClass  a
     *                                                                                                    resolved FQCN, and the argument schemas
     *                                                                                                    of a generic spelling of it, to the
     *                                                                                                    fragment that stands for it; null when
     *                                                                                                    it has none
     * @param  ReflectionClass<object>|null  $context  the class the expression was written inside, which is
     *                                                 what makes `OrderLine` resolvable at all
     * @param  array<string, array<string, mixed>>  $templates  the context's template parameters, bound — a
     *                                                          name here shadows any class of the same name,
     *                                                          as it does for PHPStan
     */
    private function __construct(
        private readonly string $source,
        private readonly Closure $schemaForClass,
        private readonly ?ReflectionClass $context,
        private readonly array $templates = [],
    ) {}

    /**
     * The schema for a type expression, or null when it says nothing useful.
     *
     * @param  Closure(string, list<array<string, mixed>>): (array<string, mixed>|null)  $schemaForClass
     * @param  ReflectionClass<object>|null  $context
     * @param  array<string, array<string, mixed>>  $templates
     * @return array<string, mixed>|null
     */
    public static function schema(string $expression, Closure $schemaForClass, ?ReflectionClass $context = null, array $templates = []): ?array
    {
        return (new self($expression, $schemaForClass, $context, $templates))->parseAll();
    }

    /**
     * The argument schemas of a generic spelling — the `Parcel` in `Envelope<Parcel>` — or null when the
     * expression is not one. This is how an `@extends` line binds a parent's template parameters: the class
     * named is known already, and what matters is what it was instantiated WITH.
     *
     * @param  Closure(string, list<array<string, mixed>>): (array<string, mixed>|null)  $schemaForClass
     * @param  ReflectionClass<object>|null  $context
     * @param  array<string, array<string, mixed>>  $templates
     * @return list<array<string, mixed>>|null
     */
    public static function genericArguments(string $expression, Closure $schemaForClass, ?ReflectionClass $context = null, array $templates = []): ?array
    {
        $parser = new self($expression, $schemaForClass, $context, $templates);

        if ($parser->name() === '') {
            return null;
        }

        $parser->spaces();
        if ($parser->peek() !== '<') {
            return null;
        }
        $parser->at++;

        $arguments = $parser->arguments('>');

        return $arguments === null ? null : array_map(static fn (?array $argument): array => $argument ?? [], $arguments);
    }

    /**
     * The type expression at the head of a `@return`/`@var` line, split from the prose that follows it.
     *
     * A tag line is `array{a: int} The page of results.` — one type expression and then English. The split
     * cannot be done on whitespace, because a type expression contains spaces of its own (`array{a: int, b:
     * string}`), so it is done by PARSING: whatever the grammar consumed is the type, and the remainder is
     * prose. That prose is worth recovering rather than discarding — it is the only description a response
     * has that an author actually wrote.
     *
     * @param  Closure(string, list<array<string, mixed>>): (array<string, mixed>|null)  $schemaForClass
     * @param  ReflectionClass<object>|null  $context
     * @param  array<string, array<string, mixed>>  $templates
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    public static function split(string $line, Closure $schemaForClass, ?ReflectionClass $context = null, array $templates = []): array
    {
        $parser = new self($line, $schemaForClass, $context, $templates);
        $schema = $parser->parseUnion();

        return [$schema, trim(substr($line, $parser->at))];
    }

    /** @return array<string, mixed>|null */
    private function parseAll(): ?array
    {
        $schema = $this->parseUnion();
        $this->spaces();

        // Trailing input means the expression was not what it claimed to be — `array{` with no close, a stray
        // token — and a half-parsed shape is worse than none.
        return $this->at >= strlen($this->source) ? $schema : null;
    }

    /** @return array<string, mixed>|null */
    private function parseUnion(): ?array
    {
        $parts = [];
        $unknown = false;

        while (true) {
            $part = $this->parseIntersection();
            $part === null ? $unknown = true : $parts[] = $part;

            $this->spaces();
            if ($this->peek() !== '|') {
                break;
            }
            $this->at++;
        }

        if ($parts === []) {
            return null;
        }

        // One arm of a union that could not be read makes the whole union a lie by omission: `Order|Draft`
        // where Draft does not resolve would publish "always an Order". The empty schema — any value — is
        // the honest answer, and is exactly what an unconstrained union is.
        return $unknown ? [] : SchemaUnion::of($parts);
    }

    /** @return array<string, mixed>|null */
    private function parseIntersection(): ?array
    {
        $parts = [];

        while (true) {
            $part = $this->parseAtomic();
            if ($part === null) {
                return null;
            }
            $parts[] = $part;

            $this->spaces();
            if ($this->peek() !== '&') {
                break;
            }
            $this->at++;
        }

        return count($parts) === 1 ? $parts[0] : ['allOf' => $parts];
    }

    /** @return array<string, mixed>|null */
    private function parseAtomic(): ?array
    {
        $this->spaces();

        if ($this->peek() === '?') {
            $this->at++;
            $inner = $this->parseAtomic();

            return $inner === null ? null : SchemaUnion::nullable($inner);
        }

        if ($this->peek() === '(') {
            $this->at++;
            $inner = $this->parseUnion();
            $this->spaces();
            if ($this->peek() !== ')') {
                return null;
            }
            $this->at++;

            return $inner === null ? null : $this->suffixes($inner);
        }

        if ($this->peek() === '\'' || $this->peek() === '"') {
            $literal = $this->stringLiteral();

            return $literal === null ? null : $this->suffixes(['const' => $literal]);
        }

        if ($this->peek() !== null && (ctype_digit($this->peek()) || ($this->peek() === '-' && ctype_digit($this->at(1))))) {
            return $this->suffixes(['const' => $this->intLiteral()]);
        }

        $name = $this->name();
        if ($name === '') {
            return null;
        }

        $this->spaces();
        if ($this->peek() === '<') {
            $this->at++;
            $arguments = $this->arguments('>');
            if ($arguments === null) {
                return null;
            }

            $generic = $this->generic($name, $arguments);

            return $generic === null ? null : $this->suffixes($generic);
        }

        if ($this->peek() === '{' && in_array(strtolower($name), ['array', 'list', 'object'], true)) {
            $this->at++;
            $shape = $this->shape();

            return $shape === null ? null : $this->suffixes($shape);
        }

        $atom = $this->named($name);

        return $atom === null ? null : $this->suffixes($atom);
    }

    /**
     * `Order[]`, and `Order[][]` for a list of lists.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function suffixes(array $base): array
    {
        while (true) {
            $this->spaces();
            if ($this->peek() !== '[' || $this->at(1) !== ']') {
                return $base;
            }
            $this->at += 2;
            $base = $base === [] ? ['type' => 'array'] : ['type' => 'array', 'items' => $base];
        }
    }

    /**
     * A bare type name: a PHPDoc scalar pseudo-type, or a class.
     *
     * The pseudo-types are here because PHPStan-flavoured PHPDoc uses them constantly and each one carries
     * real information a plain `string`/`int` would throw away — `non-empty-string` is `minLength: 1`,
     * `positive-int` is `minimum: 1`. Publishing that costs nothing and is exactly what a client generator
     * turns into a validated field.
     *
     * @return array<string, mixed>|null
     */
    private function named(string $name): ?array
    {
        return match (strtolower($name)) {
            'int', 'integer' => ['type' => 'integer'],
            'positive-int' => ['type' => 'integer', 'minimum' => 1],
            'negative-int' => ['type' => 'integer', 'maximum' => -1],
            'non-negative-int' => ['type' => 'integer', 'minimum' => 0],
            'non-positive-int' => ['type' => 'integer', 'maximum' => 0],
            'float', 'double' => ['type' => 'number'],
            'numeric' => ['type' => ['integer', 'number']],
            'string', 'class-string', 'callable-string', 'literal-string', 'lowercase-string', 'trait-string', 'interface-string' => ['type' => 'string'],
            'non-empty-string', 'non-empty-lowercase-string' => ['type' => 'string', 'minLength' => 1],
            'numeric-string' => ['type' => 'string', 'pattern' => '^-?\d+(\.\d+)?$'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'true' => ['const' => true],
            'false' => ['const' => false],
            'null', 'void' => ['type' => 'null'],
            'array-key' => ['type' => ['string', 'integer']],
            'scalar' => ['type' => ['string', 'integer', 'number', 'boolean']],
            'array', 'list', 'non-empty-array', 'non-empty-list', 'iterable' => ['type' => 'array'],
            'object', 'stdclass' => ['type' => 'object'],
            'mixed' => [],
            // `never` is not "any value", it is "no value" — an action returning it produces no body at all,
            // which is a fact the caller acts on and must not receive as an empty any-schema.
            'never', 'never-return', 'noreturn' => null,
            'callable', 'closure', 'resource' => null,
            default => $this->classNamed($name),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $arguments  a generic spelling's argument schemas; empty for a bare name
     * @return array<string, mixed>|null
     */
    private function classNamed(string $name, array $arguments = []): ?array
    {
        if (array_key_exists($name, $this->templates)) {
            return $this->templates[$name];
        }

        $lower = strtolower($name);

        if ($lower === 'self' || $lower === 'static' || $lower === '$this') {
            return $this->context === null ? null : ($this->schemaForClass)($this->context->getName(), $arguments);
        }

        $resolved = ClassNames::resolve($name, $this->context);

        return $resolved === null ? null : ($this->schemaForClass)($resolved, $arguments);
    }

    /**
     * A generic: `list<T>`, `array<K, V>`, `iterable<T>`, and the collection types that behave like them.
     *
     * `array<K, V>` is the one that has to make a decision: with an INTEGER key it is a JSON array, and with
     * any other key it is a JSON object whose members are not known in advance — `additionalProperties`.
     * Getting that backwards produces a document in which every `array<string, Money>` is a list, which a
     * generated client then fails to decode against the real payload.
     *
     * @param  list<array<string, mixed>|null>  $arguments
     * @return array<string, mixed>|null
     */
    private function generic(string $name, array $arguments): ?array
    {
        $lower = strtolower($name);
        $any = static fn (?array $schema): array => $schema ?? [];

        if ($lower === 'list' || $lower === 'non-empty-list') {
            $schema = ['type' => 'array'];
            if (($items = $any($arguments[0] ?? null)) !== []) {
                $schema['items'] = $items;
            }
            if ($lower === 'non-empty-list') {
                $schema['minItems'] = 1;
            }

            return $schema;
        }

        if (in_array($lower, ['array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'collection', 'arrayobject', 'arrayiterator'], true)) {
            $keyed = count($arguments) >= 2;
            $value = $any($arguments[$keyed ? 1 : 0] ?? null);

            if ($keyed && ! $this->isIntegerKey($arguments[0])) {
                $schema = ['type' => 'object'];
                if ($value !== []) {
                    $schema['additionalProperties'] = $value;
                }

                return $schema;
            }

            $schema = ['type' => 'array'];
            if ($value !== []) {
                $schema['items'] = $value;
            }
            if ($lower === 'non-empty-array') {
                $schema['minItems'] = 1;
            }

            return $schema;
        }

        // A generic over a class (`Page<Order>`, a fully-qualified `Collection<int, Order>`): the resolver
        // gets the arguments too, and decides what the instantiation means — see the class docblock.
        $bound = [];
        foreach ($arguments as $argument) {
            $bound[] = $argument ?? [];
        }

        return $this->classNamed($name, $bound);
    }

    /**
     * Whether an `array<K, V>` key argument denotes integer keys, i.e. a JSON array rather than an object.
     *
     * @param  array<string, mixed>|null  $key
     */
    private function isIntegerKey(?array $key): bool
    {
        if ($key === null) {
            return false;
        }

        $type = $key['type'] ?? null;

        return $type === 'integer' || (is_array($type) && $type === ['integer']);
    }

    /**
     * An array shape: `array{a: int, b?: string}` for an object, `array{int, string}` for a tuple, and a
     * trailing `...` for one that admits members it does not name.
     *
     * A `?` on the KEY is what PHPDoc uses for "may be absent", which is the same statement `required` makes
     * in JSON Schema — and is a different thing from a `?` on the VALUE, which is nullability. Conflating
     * the two documents an omissible member as one that must be present and may be null, and a client
     * generator turns that into a field it always sends.
     *
     * @return array<string, mixed>|null
     */
    private function shape(): ?array
    {
        $properties = [];
        $required = [];
        $tuple = [];
        $open = false;

        $this->spaces();
        if ($this->peek() === '}') {
            $this->at++;

            return ['type' => 'object'];
        }

        while (true) {
            $this->spaces();

            if ($this->peek() === '.' && $this->at(1) === '.' && $this->at(2) === '.') {
                $this->at += 3;
                $open = true;
                $this->spaces();
                if ($this->peek() === ',') {
                    $this->at++;

                    continue;
                }
                break;
            }

            $key = null;
            $optional = false;
            $mark = $this->at;

            if ($this->peek() === '\'' || $this->peek() === '"') {
                $key = $this->stringLiteral();
            } else {
                $candidate = $this->name();
                if ($candidate !== '') {
                    $key = $candidate;
                }
            }

            if ($key !== null) {
                $this->spaces();
                if ($this->peek() === '?') {
                    $this->at++;
                    $optional = true;
                    $this->spaces();
                }
                if ($this->peek() === ':') {
                    $this->at++;
                } else {
                    // Not `key: value` after all — it was a bare type in a tuple, so rewind and read it as one.
                    $this->at = $mark;
                    $key = null;
                    $optional = false;
                }
            }

            $value = $this->parseUnion();
            if ($value === null) {
                $value = [];
            }

            if ($key === null) {
                $tuple[] = $value;
            } else {
                $properties[$key] = $value;
                if (! $optional) {
                    $required[] = $key;
                }
            }

            $this->spaces();
            if ($this->peek() === ',') {
                $this->at++;

                continue;
            }
            break;
        }

        $this->spaces();
        if ($this->peek() !== '}') {
            return null;
        }
        $this->at++;

        if ($properties === [] && $tuple !== []) {
            return ['type' => 'array', 'prefixItems' => $tuple, 'minItems' => count($tuple), 'maxItems' => count($tuple)];
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        if (! $open) {
            // A closed shape names every member it has. Saying so is what lets a client generator produce a
            // struct rather than a struct plus a bag, and it is true by construction here — an author who
            // meant otherwise writes the `...`.
            $schema['additionalProperties'] = false;
        }

        return $schema;
    }

    /**
     * The comma-separated arguments of a generic, up to $close.
     *
     * @return list<array<string, mixed>|null>|null
     */
    private function arguments(string $close): ?array
    {
        $arguments = [];

        while (true) {
            $this->spaces();
            if ($this->peek() === $close) {
                $this->at++;

                return $arguments;
            }

            $arguments[] = $this->parseUnion();

            $this->spaces();
            if ($this->peek() === ',') {
                $this->at++;

                continue;
            }

            if ($this->peek() === $close) {
                $this->at++;

                return $arguments;
            }

            return null;
        }
    }

    private function name(): string
    {
        $this->spaces();
        $start = $this->at;
        $length = strlen($this->source);

        while ($this->at < $length) {
            $char = $this->source[$this->at];
            if (ctype_alnum($char) || $char === '_' || $char === '\\' || $char === '-' || $char === '$') {
                $this->at++;

                continue;
            }
            break;
        }

        return substr($this->source, $start, $this->at - $start);
    }

    private function stringLiteral(): ?string
    {
        $quote = $this->peek();
        if ($quote !== '\'' && $quote !== '"') {
            return null;
        }

        $this->at++;
        $start = $this->at;
        $length = strlen($this->source);

        while ($this->at < $length && $this->source[$this->at] !== $quote) {
            $this->at += $this->source[$this->at] === '\\' ? 2 : 1;
        }

        if ($this->at >= $length) {
            return null;
        }

        $value = substr($this->source, $start, $this->at - $start);
        $this->at++;

        return stripcslashes($value);
    }

    private function intLiteral(): int
    {
        $start = $this->at;
        if ($this->peek() === '-') {
            $this->at++;
        }
        while ($this->peek() !== null && ctype_digit((string) $this->peek())) {
            $this->at++;
        }

        return (int) substr($this->source, $start, $this->at - $start);
    }

    private function spaces(): void
    {
        $length = strlen($this->source);
        while ($this->at < $length && ($this->source[$this->at] === ' ' || $this->source[$this->at] === "\t" || $this->source[$this->at] === "\n" || $this->source[$this->at] === "\r")) {
            $this->at++;
        }
    }

    private function peek(): ?string
    {
        return $this->at(0);
    }

    private function at(int $ahead): ?string
    {
        $index = $this->at + $ahead;

        return $index < strlen($this->source) ? $this->source[$index] : null;
    }
}
