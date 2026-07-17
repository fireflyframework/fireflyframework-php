<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;
use Firefly\Container\Attributes\Component;

/**
 * A stereotype specialising #[Component]: because the component scan finds stereotypes via the IS_INSTANCEOF
 * attribute filter on #[Component], a #[RestController] class is auto-registered as a singleton bean and
 * resolved through the container (constructor DI works). The RouteScanner is a SEPARATE scan that reads
 * routing metadata off the same class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RestController extends Component {}
