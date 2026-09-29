<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Support;

abstract class BindingContractTestCase extends OpenApiCapstoneTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.scan.paths' => FixtureDocument::psr4('BindingFixture')];
    }
}
