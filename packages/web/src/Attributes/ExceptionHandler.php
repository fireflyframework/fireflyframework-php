<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

/** Marks a controller or #[ControllerAdvice] method as the handler for a thrown exception class. */
#[Attribute(Attribute::TARGET_METHOD)]
final class ExceptionHandler
{
    /** @param class-string<\Throwable> $exceptionClass */
    public function __construct(public readonly string $exceptionClass) {}
}
