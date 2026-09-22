<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ResolverFixture;

use Attribute;

/**
 * An attribute a HandlerMethodArgumentResolver claims a parameter by — the shape #[AuthenticationPrincipal] has
 * in firefly/security, without the package: the generator's rule is about the registry, not about security.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Tag {}
