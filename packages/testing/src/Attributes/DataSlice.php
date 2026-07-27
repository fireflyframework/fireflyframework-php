<?php

declare(strict_types=1);

namespace Firefly\Testing\Attributes;

use Attribute;

/** Class-style analog of DataSliceTestCase::dataSlice(). */
#[Attribute(Attribute::TARGET_CLASS)]
final class DataSlice
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
