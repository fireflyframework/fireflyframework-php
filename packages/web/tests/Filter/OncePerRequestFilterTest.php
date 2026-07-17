<?php

declare(strict_types=1);

use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;

it('honours url and exclude globs via shouldNotFilter', function () {
    $filter = new class extends OncePerRequestFilter
    {
        protected function urls(): array
        {
            return ['api/*'];
        }

        protected function excludes(): array
        {
            return ['api/health'];
        }

        protected function doFilter(Request $request, Closure $next): mixed
        {
            return $next($request);
        }
    };

    expect($filter->shouldNotFilter(Request::create('/web/home')))->toBeTrue()
        ->and($filter->shouldNotFilter(Request::create('/api/accounts')))->toBeFalse()
        ->and($filter->shouldNotFilter(Request::create('/api/health')))->toBeTrue();
});

it('short-circuits the chain when shouldNotFilter is true', function () {
    $filter = new class extends OncePerRequestFilter
    {
        public bool $ran = false;

        protected function urls(): array
        {
            return ['never/*'];
        }

        protected function doFilter(Request $request, Closure $next): mixed
        {
            $this->ran = true;

            return $next($request);
        }
    };

    $filter->handle(Request::create('/other'), fn ($r) => 'passed');

    expect($filter->ran)->toBeFalse();
});
