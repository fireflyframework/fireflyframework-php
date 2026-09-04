<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Illuminate\Foundation\Application;

/** The dashboard with the data browser switched ON and writes allowed. */
abstract class DataBrowserTestCase extends AdminCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.data.enabled' => $this->dataEnabled(),
            'firefly.admin.data.writable' => $this->dataWritable(),
        ];
    }

    protected function dataEnabled(): bool
    {
        return true;
    }

    protected function dataWritable(): bool
    {
        return true;
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);
    }
}
