<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

/**
 * Resolves a constraint's sentence: the `message:` element when the developer gave one, else the
 * constraint's default, with Bean Validation's `{placeholder}` syntax filled from the attribute's own
 * elements — so `#[Size(min: 1, max: 50, message: 'between {min} and {max} lines')]` publishes
 * `between 1 and 50 lines`, exactly as `@Size(message = "between {min} and {max} lines")` would.
 *
 * A static helper rather than a second method on the MessageElement trait: the default sentence and its
 * placeholders differ per attribute, and passing the two strings explicitly keeps each attribute's
 * message() a one-liner that static analysis can read without knowing the trait's host.
 */
final class ConstraintMessage
{
    /**
     * @param  array<string, int|float|string|null>  $parameters  placeholder name => value; null renders as ''
     */
    public static function resolve(?string $override, ?string $default, array $parameters = []): ?string
    {
        $template = $override ?? $default;

        if ($template === null) {
            return null;
        }

        $replacements = [];
        foreach ($parameters as $placeholder => $value) {
            $replacements['{'.$placeholder.'}'] = $value === null ? '' : (string) $value;
        }

        return $replacements === [] ? $template : strtr($template, $replacements);
    }
}
