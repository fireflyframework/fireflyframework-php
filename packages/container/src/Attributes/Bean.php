<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;
use Firefly\Container\Scope;

#[Attribute(Attribute::TARGET_METHOD)]
final class Bean
{
    public function __construct(
        public ?string $name = null,
        public Scope $scope = Scope::Singleton,
    ) {}
}
