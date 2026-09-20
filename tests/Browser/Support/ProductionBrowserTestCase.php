<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

/**
 * The production page: the trace is off AND the environment is production, so the page shows the status,
 * the code and a reassurance — no exception, no trace, and no hint footer naming APP_DEBUG (that footer
 * follows app.env, not the trace key). Nothing this suite visits is refused in production; only the
 * settings console and the datasource wizard are, and they are not visited here.
 */
abstract class ProductionBrowserTestCase extends BrowserTestCase
{
    protected function trace(): bool
    {
        return false;
    }

    protected function environment(): string
    {
        return 'production';
    }
}
