<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Support;

/**
 * The same application with the trace off and the environment production: the page a stranger sees.
 */
abstract class ProductionErrorPagesCapstoneTestCase extends ErrorPagesCapstoneTestCase
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
