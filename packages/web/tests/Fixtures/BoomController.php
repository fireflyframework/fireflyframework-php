<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use RuntimeException;

/**
 * Throws a GENERIC (non-FireflyException) Throwable so the capstone can prove the RFC-7807 renderable's
 * expectsJson() gate: a generic error renders as problem+json ONLY when the request wants JSON; otherwise it
 * falls through to Laravel's default handler. Its own base path (/boom) keeps it clear of /balances/{id}.
 *
 * It also throws the one exception Laravel's OWN handler rewrites on the way to a renderable, so the
 * capstone can prove what reaches the wire after Handler::prepareException() has had it — see enumCase().
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

    /**
     * A route-model-binding miss, as the framework raises it.
     *
     * Handler::prepareException() rewrites this into `new NotFoundHttpException($e->getMessage(), $e)`
     * BEFORE renderViaCallbacks() consults anything LaraFly registered, so the renderer sees a plain 404
     * carrying the FRAMEWORK's sentence — "Case [pending] not found on Backed Enum [App\Enums\Status]." —
     * and cannot tell it from an author's `abort(404, '…')` by class. BackedEnumCaseNotFoundException is
     * thrown rather than ModelNotFoundException, which takes the identical path, because it lives in
     * illuminate/routing (a declared dependency of this package) and the other in illuminate/database.
     *
     * @return array<string,mixed>
     */
    #[GetMapping('/enum-case')]
    public function enumCase(): array
    {
        throw new BackedEnumCaseNotFoundException('App\Enums\Status', 'pending');
    }
}
