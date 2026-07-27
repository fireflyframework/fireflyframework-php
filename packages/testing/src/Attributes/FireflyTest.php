<?php

declare(strict_types=1);

namespace Firefly\Testing\Attributes;

use Attribute;
use Illuminate\Support\ServiceProvider;

/** Class-style analog of a plain FireflyTestCase: declare providers + eager config on the test class. */
#[Attribute(Attribute::TARGET_CLASS)]
final class FireflyTest
{
    /**
     * @param  list<class-string<ServiceProvider>>  $providers
     * @param  array<string,mixed>  $config
     */
    public function __construct(
        public array $providers = [],
        public array $config = [],
    ) {}
}
