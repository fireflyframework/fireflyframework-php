<?php

declare(strict_types=1);

namespace Firefly\Web\Filter;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A WebFilter that runs at most once per request and supports url/exclude glob matching. Subclasses
 * implement doFilter(); handle() short-circuits to $next when shouldNotFilter() is true.
 */
abstract class OncePerRequestFilter implements WebFilter
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldNotFilter($request)) {
            return $next($request);
        }

        return $this->doFilter($request, $next);
    }

    abstract protected function doFilter(Request $request, Closure $next): mixed;

    /** @return list<string> glob patterns this filter applies to (empty = all paths) */
    protected function urls(): array
    {
        return [];
    }

    /** @return list<string> glob patterns explicitly excluded */
    protected function excludes(): array
    {
        return [];
    }

    public function shouldNotFilter(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->excludes() as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        $urls = $this->urls();
        if ($urls === []) {
            return false;
        }

        foreach ($urls as $pattern) {
            if (Str::is($pattern, $path)) {
                return false;
            }
        }

        return true;
    }
}
