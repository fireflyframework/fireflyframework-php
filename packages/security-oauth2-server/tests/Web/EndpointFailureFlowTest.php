<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Fixtures\Failing\FailingTokenEndpoint;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\Tests\Support\RecordingLogger;
use Illuminate\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * A machine endpoint that throws something other than an OAuth2AuthenticationException, through the real
 * pipeline: the filter answers `500 server_error` as the RFC 6749 document and logs ONE line at ERROR that names
 * the endpoint and the exception's class and place — and never its message, which for a QueryException carries
 * the bound values (codes, token hashes). The endpoint is the fixture configuration's, bound as the OAuth2Endpoints
 * bean the way an application overrides one; the logger is bound as Psr\Log\LoggerInterface before boot, which
 * is what the filter's optional LoggerInterface resolves to.
 */
abstract class EndpointFailureCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    public RecordingLogger $logger;

    protected function fixturePaths(): array
    {
        return parent::fixturePaths() + ['Firefly\\Security\\OAuth2\\Server\\Tests\\Fixtures\\Failing\\' => dirname(__DIR__).'/Fixtures/Failing'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->logger = new RecordingLogger;
        $app->instance(LoggerInterface::class, $this->logger);
    }
}

uses(EndpointFailureCapstoneTestCase::class);

it('answers 500 server_error and logs the endpoint, the exception class and its place — never the message, which a driver fills with the request\'s values', function () {
    /** @var EndpointFailureCapstoneTestCase $this */
    $this->logger->reset();

    $this->post('/oauth2/token', ['grant_type' => 'client_credentials'], ['Accept' => 'application/json'])
        ->assertStatus(500)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['error' => 'server_error', 'error_description' => 'The authorization server could not process the request.']);

    $errors = array_values(array_filter($this->logger->records, static fn (array $record): bool => $record['level'] === 'error'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toContain('OAuth2 endpoint '.FailingTokenEndpoint::class.' failed: RuntimeException at ')->toContain('FailingTokenEndpoint.php:')
        ->and($errors[0]['message'])->not->toContain(FailingTokenEndpoint::SECRET)
        ->and($errors[0]['message'])->not->toContain('insert into')
        ->and($errors[0]['context'])->toBe([]);

    // Nothing anywhere in the log carries the value, at any level or in any context.
    foreach ($this->logger->records as $record) {
        expect($record['message'])->not->toContain(FailingTokenEndpoint::SECRET)
            ->and(json_encode($record['context'], JSON_THROW_ON_ERROR))->not->toContain(FailingTokenEndpoint::SECRET);
    }
});

it('still answers a wrong method itself, before the endpoint runs: 405, Allow, no log line', function () {
    /** @var EndpointFailureCapstoneTestCase $this */
    $this->logger->reset();

    $this->getJson('/oauth2/token')
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST')
        ->assertJson(['error' => 'invalid_request']);

    expect($this->logger->records)->toBe([]);
});
