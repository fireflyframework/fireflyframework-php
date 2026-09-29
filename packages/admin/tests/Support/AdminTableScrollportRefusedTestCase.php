<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard capstone handed a `firefly.admin.table.max-height` that is not a length but a stylesheet.
 *
 * `--table-vh` is INTERPOLATED INTO A `<style>` ELEMENT, which is the one place on this page where Blade's
 * escaping buys nothing: by the time an entity would be decoded the CSS parser has already left the
 * declaration, so `1px}body{display:none` closes the rule and opens its own. TableSettings::height()
 * therefore refuses anything that is not a length or `none` and falls back to the default, and this case
 * exists to prove that end to end — through a real boot, a real request and the real sheet — rather than
 * only against the value object.
 *
 * Seeded through configOverrides() because the settings are built during the boot passes; see
 * AdminTableScrollportOffTestCase.
 */
abstract class AdminTableScrollportRefusedTestCase extends AdminCapstoneTestCase
{
    /** The payload: a length, the closing brace of the rule it lands in, and a rule of the attacker's own. */
    public const string HOSTILE_HEIGHT = '1px}body{display:none';

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.table.max-height' => self::HOSTILE_HEIGHT,
        ];
    }
}
