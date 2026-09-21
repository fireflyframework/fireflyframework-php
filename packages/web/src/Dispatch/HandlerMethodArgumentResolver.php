<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Illuminate\Http\Request;

/**
 * The extension point for binding a controller-action parameter from something other than the request's
 * path, query, headers, body or the container (Spring's HandlerMethodArgumentResolver). A resolver sees the
 * compiled binding plan — name, kind, type, `attributes` (the parameter's attribute classes) and `nullable`
 * — and claims it with supports(); ArgumentResolver asks the registered resolvers BEFORE its own kinds, so a
 * class-typed parameter a resolver understands never reaches `$container->make()`. firefly/security registers
 * one for `Authentication`, `UserDetails`, `#[AuthenticationPrincipal]` and `#[CurrentSecurityContext]`.
 */
interface HandlerMethodArgumentResolver
{
    /**
     * @param  array<string, mixed>  $binding
     */
    public function supports(array $binding): bool;

    /**
     * @param  array<string, mixed>  $binding
     */
    public function resolve(array $binding, Request $request): mixed;
}
