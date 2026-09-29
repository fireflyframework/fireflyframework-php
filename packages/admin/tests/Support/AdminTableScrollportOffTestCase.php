<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard capstone with the scrollport switched OFF — `firefly.admin.table.max-height: none`.
 *
 * `none` is an admitted value rather than an accident: it is how a deployment gives the tables back to the
 * document's own scroll, header and all, which is what every page did before this wave gave `.tw` a height.
 *
 * The value is seeded through configOverrides() and NOT with a `config()->set()` inside the test, for the
 * reason AdminTablePagedCapstoneTestCase already documents: AdminSettings — and the TableSettings hanging
 * off it — is built once by AdminRouteRegistrar during the boot passes and bound as an instance, so a set
 * from a test body arrives after the object that would have read it and the page still renders the default.
 */
abstract class AdminTableScrollportOffTestCase extends AdminCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.table.max-height' => 'none',
        ];
    }
}
