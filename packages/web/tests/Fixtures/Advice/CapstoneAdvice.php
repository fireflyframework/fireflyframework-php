<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Advice;

use Firefly\Web\Attributes\ControllerAdvice;
use Firefly\Web\Attributes\ExceptionHandler;

#[ControllerAdvice]
final class CapstoneAdvice
{
    /** @return array<string,string> */
    #[ExceptionHandler(AnotherException::class)]
    public function onAnother(AnotherException $e): array
    {
        return ['handled' => 'global-another'];
    }
}
