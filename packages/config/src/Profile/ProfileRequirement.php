<?php

declare(strict_types=1);

namespace Firefly\Config\Profile;

use ReflectionClass;

/**
 * Reads the #[Profile] requirement off a class, once, at SCAN time.
 *
 * This exists because #[Profile] shipped as pure decoration: the attribute was exported and
 * documented, but `grep -rn 'Profile::class' packages/<any>/src` returned nothing — no scanner ever
 * looked for it and no condition ever acted on it, so a class marked #[Profile('prod')] was
 * registered under every profile, including the ones the annotation exists to exclude. That is the
 * worst class of framework bug: the code says one thing, the runtime does the opposite, and nothing
 * fails.
 *
 * The reading is deliberately factored out of ConfigPropertiesScanner rather than inlined there,
 * for two reasons. First, #[Profile] is a GENERAL bean annotation — a #[ConfigProperties] DTO is
 * only the bean kind this package happens to own — so every other scanner that needs to record the
 * same requirement (firefly/context's ContextScanner over #[Component] classes, in particular) must
 * be able to produce byte-identical output without copying a regex-free but still fiddly loop.
 * Second, it keeps the normalization rules (trim, drop blanks, de-duplicate, preserve declaration
 * order) in ONE place, so a manifest compiled by one scanner and a manifest compiled by another
 * cannot disagree about what ['prod', ' prod ', ''] means.
 *
 * Reflection happens here and nowhere else: the resulting list<string> travels in the compiled
 * manifest, so nothing reflects a user class at boot to discover it is profile-gated.
 */
final class ProfileRequirement
{
    /**
     * The profiles $class declares, in declaration order, or [] when it declares none.
     *
     * #[Profile] is TARGET_CLASS and not IS_REPEATABLE, so at most one attribute can be present;
     * the loop nonetheless folds every occurrence rather than reading getAttributes()[0], so that
     * making the attribute repeatable later is a one-word change to the attribute and nothing else.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<string>
     */
    public static function namesOf(ReflectionClass $class): array
    {
        $names = [];

        foreach ($class->getAttributes(Profile::class) as $attribute) {
            foreach ($attribute->newInstance()->names as $name) {
                $name = trim($name);
                if ($name !== '' && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
