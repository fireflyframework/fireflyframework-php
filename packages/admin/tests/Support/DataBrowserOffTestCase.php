<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

/** The default posture: the dashboard on, the data browser off. */
abstract class DataBrowserOffTestCase extends DataBrowserTestCase
{
    protected function dataEnabled(): bool
    {
        return false;
    }
}
