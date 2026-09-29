<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use RuntimeException;

/**
 * Throws a GENERIC (non-FireflyException) Throwable so the capstone can prove which shape the RFC-7807
 * renderable answers in: a browser that NAMES text/html gets the HTML page, and a caller that named JSON, a
 * wildcard or nothing at all gets problem+json. Its own base path (/boom) keeps it clear of /balances/{id}.
 *
 * It also throws the one exception Laravel's OWN handler rewrites on the way to a renderable, so the
 * capstone can prove what reaches the wire after Handler::prepareException() has had it — see enumCase() —
 * and the three Laravel's handler resolves for ITSELF after the renderable has been consulted, which this
 * package must therefore decline: see validated() and unauthenticated(), and CarriedResponseFilter
 * for the third, which a controller cannot raise as far as the exception handler.
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

    /**
     * LARAVEL'S OWN VALIDATION, not LaraFly's #[Valid] — and the difference is the whole point.
     *
     * A #[Valid] body raises a FireflyException that this package describes precisely. `$validator->validate()`
     * raises Illuminate\Validation\ValidationException, which is neither a FireflyException nor an
     * HttpExceptionInterface, so ProblemMapper drops it to its default arm: claiming it published a 500
     * INTERNAL_ERROR with no `errors` member, in place of the 422 Laravel's own handler resolves one
     * `match` arm later. An application built on this framework still uses Laravel validation — in a
     * FormRequest, in a controller, in a Livewire component — so this is an ordinary route, not an exotic one.
     *
     * @return array<string,mixed>
     */
    #[PostMapping('/validated')]
    public function validated(ValidationFactory $validator, Request $request): array
    {
        $validator->make($request->all(), ['email' => ['required', 'email']])->validate();

        return ['ok' => true];
    }

    /**
     * The 401 Laravel resolves itself, through Handler::unauthenticated() — a JSON client is answered
     * `{"message": …}` at 401 and anyone else is sent to the login page. Claiming it published a 500
     * INTERNAL_ERROR to both.
     *
     * @return array<string,mixed>
     */
    #[GetMapping('/unauthenticated')]
    public function unauthenticated(): array
    {
        throw new AuthenticationException('Unauthenticated.');
    }

    /**
     * The route Handler::unauthenticated() sends a non-JSON caller to. It exists because `route('login')`
     * is what that method calls, and an application without one turns its own 401 into a
     * RouteNotFoundException — Laravel's behaviour, not this package's, but it would hide the redirect
     * this fixture is here to show.
     *
     * @return array<string,mixed>
     */
    #[GetMapping('/login', name: 'login')]
    public function login(): array
    {
        return ['login' => true];
    }
}
