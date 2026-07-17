<?php

declare(strict_types=1);

namespace Firefly\Web\Filter;

use Closure;
use Illuminate\Http\Request;

/**
 * A request filter shaped exactly like Laravel middleware (handle($request, $next)), so a WebFilter bean IS
 * registerable as global middleware. shouldNotFilter() lets a filter opt out of specific requests.
 */
interface WebFilter
{
    public function handle(Request $request, Closure $next): mixed;

    public function shouldNotFilter(Request $request): bool;
}
