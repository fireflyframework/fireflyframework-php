<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Data\DataServiceProvider;

/** The dashboard with firefly/data booted beside it, so the datasource page has a DataSettings bean to read. */
abstract class DatasourceCapstoneTestCase extends AdminCapstoneTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), DataServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.data.transaction.default-timeout' => 30];
    }
}
