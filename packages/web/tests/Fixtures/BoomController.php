<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use RuntimeException;

/**
 * Throws a GENERIC (non-FireflyException) Throwable so the capstone can prove the RFC-7807 renderable's
 * expectsJson() gate: a generic error renders as problem+json ONLY when the request wants JSON; otherwise it
 * falls through to Laravel's default handler. Its own base path (/boom) keeps it clear of /balances/{id}.
 */
#[RestController]
#[RequestMapping('/boom')]
final class BoomController
{
    /** @return array<string,mixed> */
    #[GetMapping('/generic')]
    public function generic(): array
    {
        throw new RuntimeException('kaboom');
    }
}
