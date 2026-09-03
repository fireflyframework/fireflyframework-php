<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Actuator\Introspection\BeansEndpoint;
use Firefly\Actuator\Introspection\ConditionsEndpoint;
use Firefly\Actuator\Introspection\EnvEndpoint;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionOutcome;
use Illuminate\Config\Repository;

/**
 * EndpointResponse::$body is `array<mixed>|string` (text endpoints use string) — narrow to array before
 * indexing a key, via real control-flow (not a suppressing @var/assert() override), same idiom as
 * HealthEndpointTest.php's jsonBody() helper. Named distinctly (introspectionJsonBody, not jsonBody) so
 * this file has no name collision with that one when Pest loads both into the same process for a
 * whole-package run — this file must also pass PHPStan and run standalone (the brief's own Step 2/4
 * command targets this file alone), so it cannot depend on another test file's global function.
 *
 * @return array<mixed>
 */
function introspectionJsonBody(EndpointResponse $response): array
{
    if (! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $response->body;
}

/**
 * Narrows one `array<mixed>` value under $key to a `list<array<mixed>>` via real runtime checks (not a
 * suppressing @var override) — each element is genuinely verified to be an array before being added to
 * the returned list, so PHPStan sees a real array type (offset-accessible) at every row, rather than a
 * bare `mixed` that a string/int offset access would reject.
 *
 * @param  array<mixed>  $body
 * @return list<array<mixed>>
 */
function introspectionRows(array $body, string $key): array
{
    $value = $body[$key] ?? null;
    if (! is_array($value)) {
        throw new RuntimeException("Expected [{$key}] to be an array.");
    }

    $rows = [];
    foreach ($value as $row) {
        if (! is_array($row)) {
            throw new RuntimeException("Expected each [{$key}] entry to be an array.");
        }
        $rows[] = $row;
    }

    return $rows;
}

it('masks sensitive values in /env', function () {
    $repository = new Repository(['firefly' => ['datasource' => ['password' => 'hunter2', 'host' => 'db.local'], 'security' => ['jwt' => ['secret' => 'sh']]]]);

    $body = (new EnvEndpoint($repository))->handle(new EndpointRequest('GET', []))->body;

    $flat = json_encode($body);
    expect($flat)->toContain('db.local')->not->toContain('hunter2')->not->toContain('"sh"')
        ->and($flat)->toContain('******');
});

// The audit finding, pinned on /env as well as /configprops: the original mask() tested the key ONLY on the
// scalar branch, so a sensitive key holding an ARRAY was recursed into and each leaf judged on its own
// harmless name. A JWT keyring under `firefly.security.jwt.keys` therefore rendered every private key in
// full — and a keyring, a credentials pair or a per-tenant token map is what a real secret actually looks
// like, so the bypass covered the cases that mattered most.
it('masks a sensitive /env key whose value is an array, leaking neither values nor shape', function () {
    $repository = new Repository(['firefly' => ['security' => ['jwt' => [
        'keys' => ['active' => 'PRIVATE-A', 'previous' => 'PRIVATE-B'],
        'issuer' => 'https://auth.local',
    ]]]]);

    $body = (new EnvEndpoint($repository))->handle(new EndpointRequest('GET', []))->body;

    $flat = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($flat)->not->toContain('PRIVATE-A')
        ->and($flat)->not->toContain('PRIVATE-B')
        ->and($flat)->not->toContain('active')
        ->and($flat)->toContain('https://auth.local')
        ->and($flat)->toContain('******');
});

it('lists beans from the boot-time catalog', function () {
    $catalog = new BeansCatalog([
        ['class' => 'App\\Foo', 'stereotype' => 'service', 'scope' => 'Singleton', 'name' => null, 'interfaces' => ['App\\FooPort'], 'beans' => []],
    ]);

    $body = introspectionJsonBody((new BeansEndpoint($catalog))->handle(new EndpointRequest('GET', [])));
    $beans = introspectionRows($body, 'beans');

    expect($beans[0]['class'])->toBe('App\\Foo')->and($beans[0]['interfaces'])->toBe(['App\\FooPort']);
});

it('reports condition matches and non-matches', function () {
    $report = new ConditionEvaluationReport;
    $report->record('App\\A', 'ConditionalOnProperty', ConditionOutcome::match('present'));
    $report->record('App\\B', 'ConditionalOnMissingBean', ConditionOutcome::noMatch('bean exists'));

    $body = introspectionJsonBody((new ConditionsEndpoint($report))->handle(new EndpointRequest('GET', [])));
    $positive = introspectionRows($body, 'positiveMatches');
    $negative = introspectionRows($body, 'negativeMatches');

    expect($positive)->toHaveCount(1)
        ->and($negative)->toHaveCount(1)
        ->and($negative[0]['class'])->toBe('App\\B');
});
