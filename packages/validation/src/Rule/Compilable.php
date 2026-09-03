<?php

declare(strict_types=1);

namespace Firefly\Validation\Rule;

/**
 * Lets a ValidationRule declare the constructor arguments that reconstruct it, for the compiled constraint
 * manifest.
 *
 * The manifest is a plain PHP array literal produced with var_export, so a rule OBJECT cannot be written
 * into it directly; it is stored as the envelope ['@rule' => Class, 'args' => [...]] and rebuilt with
 * `new $class(...$args)` at load time. ConstraintManifestCompiler recovers `args` automatically for the
 * ordinary PHP 8 shape — every constructor parameter promoted to a property — because promotion guarantees a
 * property mirrors each parameter, so reflection can read the values back out.
 *
 * Promotion is not always possible: a constructor may normalise its input (upper-casing, parsing, deriving),
 * assign to differently named properties, or accept a value it does not keep. Reflection cannot invert any of
 * that, and guessing would compile a rule that behaves differently from the one the developer wrote. Such a
 * rule implements this interface and answers for itself; the arguments must be var_export-safe (null,
 * scalars, enums, or arrays of those) and, applied to the constructor, must produce an equivalent rule.
 *
 * A rule that is neither promotion-shaped nor Compilable is rejected at COMPILE time with an actionable
 * ConfigurationException, never silently rehydrated with defaults at boot.
 */
interface Compilable
{
    /**
     * @return list<mixed> positional constructor arguments, in declaration order
     */
    public function constructorArguments(): array;
}
