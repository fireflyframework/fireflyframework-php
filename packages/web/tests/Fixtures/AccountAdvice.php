<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Attributes\ControllerAdvice;
use Firefly\Web\Attributes\ExceptionHandler;

#[ControllerAdvice]
final class AccountAdvice
{
    /** @return array<string,string> */
    #[ExceptionHandler(ResourceNotFoundException::class)]
    public function notFound(ResourceNotFoundException $e): array
    {
        return ['handled' => 'global-not-found'];
    }
}
