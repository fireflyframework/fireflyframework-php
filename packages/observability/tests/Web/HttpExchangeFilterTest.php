<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Observability\HttpExchanges\HttpExchange;
use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;
use Firefly\Observability\HttpExchanges\InMemoryHttpExchangeRecorder;
use Firefly\Observability\Web\HttpExchangeFilter;
use Firefly\Web\Filter\CorrelationIdFilter;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use RuntimeException as PhpRuntimeException;

/**
 * The filter is tested against a BARE Illuminate Request with no booted application — deliberately, and it is
 * why the correlation id is read off the request header rather than out of Context. The Context facade throws
 * "A facade root has not been set" without an application, which would have forced every one of these cases
 * through a full framework boot; a filter whose only test needs a full boot is a filter whose edge cases stop
 * being tested. MetricsFilterTest tests its twin the same way.
 *
 * @param  array<string, mixed>  $firefly  dot-keyed firefly.* config, as an application would set it
 */
function httpExchangeFilter(HttpExchangeRecorder $recorder, array $firefly = []): HttpExchangeFilter
{
    return new HttpExchangeFilter($recorder, new Config(new ConfigRepository($firefly)));
}

/** @return list<array<string, mixed>> */
function recordedRows(InMemoryHttpExchangeRecorder $recorder): array
{
    return array_map(static fn (HttpExchange $e): array => $e->toArray(), $recorder->exchanges());
}

it('records the route TEMPLATE, not the raw path, so one busy endpoint cannot fill the buffer', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);

    $request = Request::create('/users/42', 'GET');
    $route = new Route('GET', 'users/{id}', []);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-abc');

    httpExchangeFilter($recorder)->handle($request, fn () => new Response('ok', 200));

    $rows = recordedRows($recorder);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['uri'])->toBe('/users/{id}')
        ->and($rows[0]['method'])->toBe('GET')
        ->and($rows[0]['status'])->toBe(200)
        ->and($rows[0]['correlationId'])->toBe('corr-abc')
        ->and($rows[0]['durationMs'])->toBeFloat()
        // No headers key at all unless capture is explicitly enabled — this is the security default.
        ->and(array_key_exists('requestHeaders', $rows[0]))->toBeFalse()
        ->and($rows[0]['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
});

/**
 * MetricsFilter collapses an unmatched route to the bounded 'UNKNOWN' sentinel because there the value becomes
 * a metric TAG and unbounded tags are unbounded series. Here the value lands in a fixed-size ring, so
 * cardinality costs nothing — and 'UNKNOWN' would delete the single most useful thing this endpoint does, which
 * is telling an operator WHICH url is 404ing. The query string is still stripped: '?api_key=...' is exactly the
 * leak this feature is otherwise designed to avoid.
 */
it('falls back to the raw path for an unmatched route and strips the query string', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);

    $request = Request::create('/no-such-route/12345?api_key=super-secret&token=leaky', 'GET');
    httpExchangeFilter($recorder)->handle($request, fn () => new Response('not found', 404));

    $rows = recordedRows($recorder);

    expect($rows[0]['uri'])->toBe('/no-such-route/12345')
        ->and($rows[0]['status'])->toBe(404);
});

it('caps an attacker-controlled path so one crawler cannot put kilobytes into every ring slot', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);

    $request = Request::create('/'.str_repeat('a', 4096), 'GET');
    httpExchangeFilter($recorder)->handle($request, fn () => new Response('not found', 404));

    $uri = $recorder->exchanges()[0]->uri;

    expect(mb_strlen($uri))->toBeLessThanOrEqual(256)
        ->and($uri)->toEndWith('…');
});

/**
 * A dashboard is a POLLING client. Left in, a panel refreshing /actuator/httpexchanges every few seconds would
 * — on the long-lived worker that is the only place the default recorder retains anything — evict every genuine
 * request from the ring and then show the operator nothing but their own polling.
 */
it('does not record management traffic by default', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);
    $filter = httpExchangeFilter($recorder);

    $filter->handle(Request::create('/actuator/httpexchanges', 'GET'), fn () => new Response('{}', 200));
    $filter->handle(Request::create('/actuator', 'GET'), fn () => new Response('{}', 200));

    expect($recorder->exchanges())->toBe([]);
});

it('follows a relocated management base path, and honours an explicit exclude list that replaces the default', function () {
    $moved = new InMemoryHttpExchangeRecorder(10);
    httpExchangeFilter($moved, ['firefly.management.endpoints.web.base-path' => '/manage'])
        ->handle(Request::create('/manage/health', 'GET'), fn () => new Response('{}', 200));

    expect($moved->exchanges())->toBe([]);

    // An explicit list REPLACES the default, so management traffic is recorded again unless it is named.
    $explicit = new InMemoryHttpExchangeRecorder(10);
    $filter = httpExchangeFilter($explicit, ['firefly.observability.httpexchanges.exclude' => ['internal/*']]);
    $filter->handle(Request::create('/actuator/health', 'GET'), fn () => new Response('{}', 200));
    $filter->handle(Request::create('/internal/ping', 'GET'), fn () => new Response('{}', 200));

    expect(array_map(static fn (HttpExchange $e): string => $e->uri, $explicit->exchanges()))->toBe(['/actuator/health']);
});

/**
 * Header capture is opt-in because an exchange log that records headers verbatim is the canonical way one of
 * these endpoints leaks credentials — and `Authorization` is the header the EnvEndpoint config rule cannot see,
 * which is why HeaderMasker widens it.
 */
it('captures no headers by default and masks the credential-bearing ones when capture is enabled', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);

    $request = Request::create('/thing', 'GET');
    $request->headers->set('Authorization', 'Bearer super-secret-jwt');
    $request->headers->set('X-Api-Key', 'k-123');
    $request->headers->set('Accept', 'application/json');

    httpExchangeFilter($recorder, ['firefly.observability.httpexchanges.include-headers' => true])
        ->handle($request, fn () => new Response('ok', 200));

    $headers = $recorder->exchanges()[0]->requestHeaders;

    expect($headers['authorization'])->toBe('******')
        ->and($headers['x-api-key'])->toBe('******')
        ->and($headers['accept'])->toBe('application/json');
});

it('records a thrown request as a 500 and rethrows so the problem renderer still handles it', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);
    $request = Request::create('/boom', 'GET');

    expect(fn () => httpExchangeFilter($recorder)->handle($request, function () {
        throw new PhpRuntimeException('x');
    }))->toThrow(PhpRuntimeException::class);

    expect($recorder->exchanges()[0]->status)->toBe(500)
        ->and($recorder->exchanges()[0]->uri)->toBe('/boom');
});

/**
 * With the cache-backed recorder every request performs cache I/O, so an unguarded recorder would let a Redis
 * blip turn every 200 in the application into a 500 — an availability incident caused entirely by the telemetry
 * meant to diagnose one. An exchange row has no effect on the response, so losing the row is the only correct
 * failure.
 */
it('never lets a failing recorder change the response', function () {
    $exploding = new class implements HttpExchangeRecorder
    {
        public function record(HttpExchange $exchange): void
        {
            throw new PhpRuntimeException('cache is down');
        }

        /** @return list<HttpExchange> */
        public function exchanges(): array
        {
            return [];
        }

        public function capacity(): int
        {
            return 1;
        }

        public function recorded(): int
        {
            return 0;
        }

        public function storage(): string
        {
            return 'exploding';
        }

        public function processLocal(): bool
        {
            return true;
        }
    };

    // WebFilter::handle() is declared `mixed` (a filter may legitimately return whatever the pipeline passes
    // along), so narrow through real control flow rather than a suppressing docblock override or an assert().
    $response = httpExchangeFilter($exploding)->handle(Request::create('/thing', 'GET'), fn () => new Response('ok', 200));

    if (! $response instanceof Response) {
        throw new PhpRuntimeException('The filter must pass the inner response through untouched.');
    }

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('ok');
});
