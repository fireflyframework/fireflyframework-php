<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/**
 * The dashboard with the data browser configured for TEN rows a page — `firefly.admin.data.page-size: 10`,
 * a value the dashboard-wide offered set (25, 50, 100, 200) does not contain.
 *
 * Ten is the interesting number twice over. It is the size the old hand-rolled pager on this page offered
 * and the shared control does not, so a deployment that had chosen it is exactly the one a shared listing
 * could silently overrule; and because it is outside the shared set, it also proves that the composition in
 * `TableSettings::boundedBy()` forces the configured default INTO the rendered set rather than leaving the
 * `<select>` unable to say where it is.
 *
 * Seeded through configOverrides() and NOT with a `config()->set()` inside the test, for the reason
 * AdminTableScrollportOffTestCase already documents: AdminSettings and the DataBrowser are built during the
 * boot passes and bound as instances, so a set from a test body arrives after the objects that would have
 * read it.
 */
abstract class DataBrowserPageSizeTestCase extends DataBrowserTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.data.page-size' => 10,
        ];
    }
}
