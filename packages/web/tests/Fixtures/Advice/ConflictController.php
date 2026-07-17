<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Advice;

use Firefly\Web\Attributes\ExceptionHandler;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
#[RequestMapping('/errors')]
final class ConflictController
{
    /** @return array<string,mixed> */
    #[GetMapping('/conflict')]
    public function conflict(): array
    {
        throw new CustomBusinessException('account is frozen');
    }

    /** @return array<string,mixed> */
    #[GetMapping('/another')]
    public function another(): array
    {
        throw new AnotherException('downstream refused');
    }

    /**
     * Controller-LOCAL handler — beats any global #[ControllerAdvice] for CustomBusinessException. Its return
     * value renders at the exception's httpStatus() (409), not the route's 200 (exercises the matched-handler
     * status correction in Task 17's ControllerDispatcher).
     *
     * @return array<string,string>
     */
    #[ExceptionHandler(CustomBusinessException::class)]
    public function onConflict(CustomBusinessException $e): array
    {
        return ['handled' => 'local-conflict', 'code' => $e->errorCode()];
    }
}
