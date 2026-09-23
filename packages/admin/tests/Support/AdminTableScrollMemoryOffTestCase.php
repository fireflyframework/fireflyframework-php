<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard capstone with the scroll restore switched OFF — `firefly.admin.table.remember-scroll: false`.
 *
 * Off means the script is not emitted, not that it is emitted and does nothing: a deployment that declines
 * this declines the `sessionStorage` writes with it, and a flag the page still carries would have left the
 * writes happening behind a branch nobody can see from the outside.
 *
 * Seeded through configOverrides() and NOT with a `config()->set()` inside the test, for the reason
 * AdminTablePagedCapstoneTestCase already documents: AdminSettings — and the TableSettings hanging off it —
 * is built once by AdminRouteRegistrar during the boot passes and bound as an instance, so a set from a
 * test body arrives after the object that would have read it and the page still renders the default.
 */
abstract class AdminTableScrollMemoryOffTestCase extends AdminCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.table.remember-scroll' => false,
        ];
    }
}
