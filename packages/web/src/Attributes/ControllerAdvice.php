<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;
use Firefly\Container\Attributes\Component;

/** A #[Component] stereotype whose #[ExceptionHandler] methods are GLOBAL (apply across controllers). */
#[Attribute(Attribute::TARGET_CLASS)]
class ControllerAdvice extends Component {}
