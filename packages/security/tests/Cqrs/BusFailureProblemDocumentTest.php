<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Security\Tests\Fixtures\Cqrs\FailingCommand;
use Firefly\Security\Tests\Fixtures\Cqrs\FailingCommandHandler;
use Firefly\Security\Tests\Fixtures\Cqrs\FailingQuery;
use Firefly\Security\Tests\Fixtures\Cqrs\FailingQueryHandler;
use Firefly\Security\Tests\Fixtures\Cqrs\Failures;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
 | ONE FAILURE, ONE SENTENCE, WHICHEVER SEAM IT LEFT THROUGH.
 |
 | DefaultCommandBus and DefaultQueryBus wrap every handler throwable in a Command/QueryProcessingException,
 | whose message is "Processing command [<FQCN>] failed: <the cause's message>". Until the kernel grew a
 | public-detail seam that message WAS the problem document's `detail`, so a handler's QueryException put
 | its SQL and bindings on the wire — in production, with disclosure off — where the same throwable raised
 | by a controller answered with the opaque sentence, and an authored refusal carried the command's class
 | name in front of the sentence the application wrote for its client.
 |
 | These drive both seams through the real HTTP pipeline — WebServiceProvider's renderable, the real
 | buses resolved from the application context, a compiled HandlerManifest — and compare what a client
 | receives.
 */

abstract class BusFailureTestCase extends FireflyTestCase
{
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
        ];
    }

    protected function configOverrides(): array
    {
        return ['app.debug' => false, 'firefly.web.problem.disclose' => false];
    }

    /** Turns `firefly.web.problem.disclose` on — what a debugging deployment sets — for the rest of the test. */
    public function disclose(): void
    {
        config()->set('firefly.web.problem.disclose', true);
        $this->app()->forgetInstance(ErrorPageSettings::class);
        $this->app()->forgetInstance(ProblemDetailsRenderer::class);
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $app->instance(HandlerManifest::class, new HandlerManifest([
            new HandlerDescriptor(FailingCommand::class, FailingCommandHandler::class, 'handle', HandlerKind::Command),
            new HandlerDescriptor(FailingQuery::class, FailingQueryHandler::class, 'handle', HandlerKind::Query),
        ], []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/controller/{failure}', static fn (string $failure): never => throw Failures::make($failure));
        Route::post('/command/{failure}', static function (string $failure, ApplicationContext $context): mixed {
            /** @var CommandBus $bus */
            $bus = $context->get(CommandBus::class);

            return $bus->send(new FailingCommand($failure));
        });
        Route::post('/query/{failure}', static function (string $failure, ApplicationContext $context): mixed {
            /** @var QueryBus $bus */
            $bus = $context->get(QueryBus::class);

            return $bus->ask(new FailingQuery($failure));
        });
    }

    /**
     * The problem document a JSON client receives, and its raw body.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function problem(string $path): array
    {
        $response = $this->postJson($path);
        $response->assertHeader('Content-Type', 'application/problem+json');

        /** @var array<string, mixed> $document */
        $document = $response->json();

        return [$document, (string) $response->getContent()];
    }

    /** @return TestResponse<Response> */
    public function page(string $path): TestResponse
    {
        return $this->post($path, [], ['Accept' => 'text/html']);
    }
}

/**
 * The document minus the members that describe the request rather than the failure.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function busFailureIdentity(array $document, string ...$also): array
{
    return array_diff_key($document, array_flip(['instance', 'traceId', 'correlationId', 'timestamp', ...$also]));
}

uses(BusFailureTestCase::class);

/**
 * The opaque sentence naming the document's own reference.
 *
 * @param  array<string, mixed>  $document
 */
function busFailureOpaque(array $document): string
{
    $reference = $document['traceId'] ?? null;

    return sprintf(ProblemMapper::OPAQUE_WITH_REFERENCE, is_string($reference) ? $reference : '');
}

describe('with disclosure off (production)', function () {
    it('answers an unhandled handler throwable with the opaque sentence a controller gets, never its message or the message class', function (string $seam, string $failure, string $code) {
        /** @var BusFailureTestCase $this */
        [$fromController] = $this->problem("/controller/{$failure}");
        [$fromBus, $raw] = $this->problem("/{$seam}/{$failure}");

        expect($fromBus['status'])->toBe(500)
            ->and($fromBus['code'])->toBe($code)
            ->and($fromBus['detail'])->toBe(busFailureOpaque($fromBus))
            ->and($fromController['detail'])->toBe(busFailureOpaque($fromController))
            // The wrapper keeps its own code (and the `type` derived from it); everything else is the controller's.
            ->and(busFailureIdentity($fromBus, 'detail', 'code', 'type'))->toBe(busFailureIdentity($fromController, 'detail', 'code', 'type'));

        foreach (['SELECT', 'select', 'secret', Failures::BINDING, 'no such table', 'Processing', 'FailingCommand', 'FailingQuery', 'Fixtures'] as $leak) {
            expect($raw)->not->toContain($leak);
        }
    })->with([
        'a RuntimeException from a command' => ['command', 'runtime', 'COMMAND_PROCESSING_ERROR'],
        'a QueryException from a command' => ['command', 'query', 'COMMAND_PROCESSING_ERROR'],
        'a RuntimeException from a query' => ['query', 'runtime', 'QUERY_PROCESSING_ERROR'],
        'a QueryException from a query' => ['query', 'query', 'QUERY_PROCESSING_ERROR'],
    ]);

    it('publishes an authored cause exactly as a controller does, detail included', function (string $seam, string $failure, int $status, string $detail) {
        /** @var BusFailureTestCase $this */
        [$fromController] = $this->problem("/controller/{$failure}");
        [$fromBus, $raw] = $this->problem("/{$seam}/{$failure}");

        expect(busFailureIdentity($fromBus))->toBe(busFailureIdentity($fromController))
            ->and($fromBus['status'])->toBe($status)
            ->and($fromBus['detail'])->toBe($detail)
            ->and($raw)->not->toContain('Processing')
            ->and($raw)->not->toContain('Fixtures');
    })->with([
        'a 409 from a command' => ['command', 'conflict', 409, 'The wallet cannot be overdrawn.'],
        'a 409 from a query' => ['query', 'conflict', 409, 'The wallet cannot be overdrawn.'],
        'an authored 503 from a command' => ['command', 'unavailable', 503, 'The ledger is closed for the night; try again after 06:00.'],
        'an authored 503 from a query' => ['query', 'unavailable', 503, 'The ledger is closed for the night; try again after 06:00.'],
    ]);

    it('puts the cause\'s own sentence on the HTML page for an authored refusal, and nothing of an unhandled one', function () {
        /** @var BusFailureTestCase $this */
        $authored = $this->page('/command/conflict')->assertStatus(409);
        $unhandled = $this->page('/command/query')->assertStatus(500);

        expect((string) $authored->getContent())->toContain('The wallet cannot be overdrawn.')
            ->and((string) $authored->getContent())->not->toContain('Processing command');

        foreach (['from users', Failures::BINDING, 'no such table', 'Processing', 'FailingCommand'] as $leak) {
            expect((string) $unhandled->getContent())->not->toContain($leak);
        }
    });
});

describe('with disclosure on (debug)', function () {
    it('publishes the cause\'s own message, as a controller does, without the message class in front of it', function (string $seam, string $failure) {
        /** @var BusFailureTestCase $this */
        $this->disclose();
        [$fromController] = $this->problem("/controller/{$failure}");
        [$fromBus] = $this->problem("/{$seam}/{$failure}");

        expect($fromBus['status'])->toBe(500)
            ->and($fromBus['detail'])->toBe(Failures::make($failure)->getMessage())
            ->and($fromBus['detail'])->toBe($fromController['detail']);
    })->with([
        'a RuntimeException from a command' => ['command', 'runtime'],
        'a QueryException from a command' => ['command', 'query'],
        'a RuntimeException from a query' => ['query', 'runtime'],
        'a QueryException from a query' => ['query', 'query'],
    ]);
});
