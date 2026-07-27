<?php

declare(strict_types=1);

namespace Firefly\Testing\Attributes;

use Attribute;

/** Class-style analog of WebSliceTestCase::webSlice(). */
#[Attribute(Attribute::TARGET_CLASS)]
final class WebSlice
{
    /**
     * @param  array<string,string>  $scan
     * @param  array<class-string,object>  $overrides
     */
    public function __construct(
        public array $scan = [],
        public array $overrides = [],
    ) {}
}
