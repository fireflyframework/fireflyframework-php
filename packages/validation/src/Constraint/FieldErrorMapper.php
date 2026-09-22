<?php

declare(strict_types=1);

namespace Firefly\Validation\Constraint;

use Firefly\Kernel\Error\FieldError;
use Firefly\Validation\MessageStyle;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Arr;

/**
 * Turns a failed Illuminate run into Spring-shaped FieldErrors — the runtime half of what the scanner
 * compiled into ConstraintDescriptors. Reflection-free; runs on every 422.
 *
 * THE SOURCE IS failed(), NOT THE MESSAGE BAG. Validator::failed() is `attribute => [RuleKey => parameters]`
 * — the studly rule name (`Required`, `Regex`, `Gt`) or the rule object's class, with the parameters of the
 * rule that failed. That is what a ConstraintDescriptor can own: exact (name, parameters) first, so
 * #[Pattern]'s `regex:/^…$/D` is told from #[NotBlank]'s `regex:/\S/` on the same property; name alone
 * second. The message bag is read only for a rule no constraint gave a sentence to (a #[Rules] without
 * `message:`, a third-party constraint) and for the `laravel` style, zipped by position: Laravel adds
 * exactly one message per failing string rule and every first-party rule object fails at most once, so the
 * positions line up, and the first message is the fallback for a third-party object that calls $fail twice.
 *
 * ONE VIOLATION PER CONSTRAINT. #[NotBlank] against an array fails `string` and `regex` at once; #[Positive]
 * against "abc" fails `numeric` and `gt:0`. In the `constraint` style both would read the same sentence
 * twice, so the second is dropped — Spring reports one ConstraintViolation per constraint. In the `laravel`
 * style every sentence is different and every one is kept, exactly as before — except a developer's own
 * `message:` element, which is their sentence rather than the framework's and wins in both styles (and is
 * de-duplicated like any constraint sentence).
 *
 * TWO SPELLINGS OF ONE PATH. Illuminate expands a wildcard rule `lines.*.sku` per element and reports
 * `lines.0.sku`; the manifest compiled the element's constraints under the wildcard, so the numeric segments
 * become `*` again for the lookup (manifestPath) and `[0]` for the client (fieldPath) — Spring's spelling for
 * a list element, and the one the client wrote in JSON. A property name can never be purely numeric, so a
 * numeric dot-segment is always an index.
 */
final class FieldErrorMapper
{
    public function __construct(private readonly MessageStyle $style) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<ConstraintDescriptor>>  $constraints  keyed by manifest path (`lines.*.sku`)
     * @return list<FieldError>
     */
    public function map(ValidatorContract $validator, array $data, array $constraints): array
    {
        /** @var array<string, list<string>> $messages */
        $messages = $validator->errors()->messages();
        /** @var array<string, array<string, array<int, mixed>>> $failed */
        $failed = $validator->failed();

        $errors = [];
        foreach ($failed as $attribute => $rules) {
            $descriptors = $constraints[self::manifestPath($attribute)] ?? [];
            $sentences = $messages[$attribute] ?? [];
            $reported = [];
            $position = 0;

            foreach ($rules as $rule => $parameters) {
                $owner = self::owner($descriptors, $rule, $parameters);
                $laravel = $sentences[$position] ?? $sentences[0] ?? 'is invalid';
                $position++;

                $sentence = null;
                if ($owner !== null && ($this->style === MessageStyle::Constraint || $owner->own)) {
                    $sentence = $owner->message;

                    // A second failed rule of a constraint already reported would repeat its sentence.
                    if ($sentence !== null) {
                        if (isset($reported[$owner->name])) {
                            continue;
                        }
                        $reported[$owner->name] = true;
                    }
                }

                $errors[] = new FieldError(
                    field: self::fieldPath($attribute),
                    message: $sentence ?? $laravel,
                    rejectedValue: Arr::get($data, $attribute),
                    constraint: $owner?->name,
                );
            }
        }

        return $errors;
    }

    /** `lines.0.sku` → `lines.*.sku`: the key the manifest compiled a list element's constraints under. */
    public static function manifestPath(string $attribute): string
    {
        return (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $attribute);
    }

    /** `lines.0.sku` → `lines[0].sku`: Spring's spelling of a list element, the one the client wrote. */
    public static function fieldPath(string $attribute): string
    {
        return (string) preg_replace('/\.(\d+)(?=\.|$)/', '[$1]', $attribute);
    }

    /**
     * @param  list<ConstraintDescriptor>  $descriptors
     * @param  array<int, mixed>  $parameters
     */
    private static function owner(array $descriptors, string $rule, array $parameters): ?ConstraintDescriptor
    {
        foreach ($descriptors as $descriptor) {
            if ($descriptor->owns($rule, $parameters)) {
                return $descriptor;
            }
        }

        foreach ($descriptors as $descriptor) {
            if ($descriptor->names($rule)) {
                return $descriptor;
            }
        }

        return null;
    }
}
