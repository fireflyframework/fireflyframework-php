<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One route per failure the error surfaces have to describe, so the capstone can ask the REAL pipeline what
 * a browser and a client each receive.
 *
 * Every throwable here is one an application actually produces: a business 404 with a sentence written for
 * the caller, an abort() with the author's own, the two security refusals, a wrong verb the ROUTER raises
 * (which is the only way to get a real Allow header), and a wrapped internal failure whose cause is the
 * interesting half. Its own base path keeps it clear of the other fixtures' routes.
 */
#[RestController]
#[RequestMapping('/err')]
final class ErrorsController
{
    /** @return array<string,mixed> */
    #[GetMapping('/missing')]
    public function missing(): array
    {
        throw new ResourceNotFoundException('Order 42 does not exist.', 'ORDER_NOT_FOUND');
    }

    /** @return array<string,mixed> */
    #[GetMapping('/aborted')]
    public function aborted(): array
    {
        throw new NotFoundHttpException('No such tenant.');
    }

    /** @return array<string,mixed> */
    #[GetMapping('/refused')]
    public function refused(): array
    {
        throw new AuthenticationException('Authentication is required to access this resource.');
    }

    /** @return array<string,mixed> */
    #[GetMapping('/denied')]
    public function denied(): array
    {
        throw new AuthorizationException('Access is denied.', 'ACCESS_DENIED');
    }

    /** @return array<string,mixed> */
    #[PostMapping('/submit')]
    public function submit(): array
    {
        return ['ok' => true];
    }

    /** @return array<string,mixed> */
    #[GetMapping('/wrecked')]
    public function wrecked(): array
    {
        throw new LogicException('The fixture failed on purpose.', 0, new RuntimeException('the inner cause'));
    }
}
