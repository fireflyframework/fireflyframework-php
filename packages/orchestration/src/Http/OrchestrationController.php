<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Http;

use Closure;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Orchestration\Core\Exception\DeadLetterNotFoundException;
use Firefly\Orchestration\Core\Exception\ExecutionNotFoundException;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Core\ExecutionOutcome;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Dlq\DeadLetterRetrier;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The REST surface (mounted by OrchestrationRouteRegistrar only while firefly.orchestration.rest.enabled).
 * It is an operator and integration surface, not a public API: the application protects it with its own
 * middleware or network policy. Every refusal the engines raise is a FireflyException that already carries
 * its HTTP status (404 unknown definition or execution, 409 wrong state or held lock, 503 disabled pattern or
 * backpressure, 504 timeout), so the controller translates it once instead of guessing per action.
 */
final class OrchestrationController
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly ExecutionPersistenceProvider $persistence,
        private readonly DeadLetterService $deadLetters,
        private readonly DeadLetterRetrier $retrier,
        private readonly OrchestrationSettings $settings,
    ) {}

    public function startSaga(Request $request, string $name): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => $this->started($this->orchestrator->start(ExecutionPattern::Saga, $name, self::input($request), self::headers($request))));
    }

    public function startTcc(Request $request, string $name): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => $this->started($this->orchestrator->start(ExecutionPattern::Tcc, $name, self::input($request), self::headers($request))));
    }

    public function startWorkflow(Request $request, string $id): HttpResponse
    {
        return $this->guard(function () use ($request, $id): HttpResponse {
            $correlationId = self::body($request)['correlationId'] ?? null;
            $state = $this->orchestrator->workflows()->start($id, self::input($request), self::headers($request), is_string($correlationId) && $correlationId !== '' ? $correlationId : null);

            return $this->started(ExecutionOutcome::fromWorkflow($state));
        });
    }

    public function executions(Request $request): HttpResponse
    {
        return $this->guard(function () use ($request): HttpResponse {
            $pattern = $request->query('pattern');
            $status = $request->query('status');
            $name = $request->query('name');
            $criteria = new ExecutionCriteria(
                pattern: is_string($pattern) ? ExecutionPattern::tryFrom(strtoupper($pattern)) : null,
                status: is_string($status) ? ExecutionStatus::tryFrom(strtoupper($status)) : null,
                name: is_string($name) && $name !== '' ? $name : null,
                limit: self::limit($request),
            );

            return new JsonResponse(['executions' => array_map(static fn (ExecutionState $state): array => [
                'correlationId' => $state->correlationId,
                'name' => $state->executionName,
                'pattern' => $state->pattern->value,
                'status' => $state->status->value,
                'updatedAt' => ExecutionState::instant($state->updatedAt),
                'waitingOn' => $state->waitingOn,
                'failureReason' => $state->failureReason,
            ], $this->persistence->find($criteria))]);
        });
    }

    public function execution(string $id): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => new JsonResponse(($this->persistence->findById($id) ?? throw new ExecutionNotFoundException($id))->toArray()));
    }

    public function signal(Request $request, string $id, string $signal): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => new JsonResponse($this->orchestrator->workflows()->signal($id, $signal, self::body($request)['payload'] ?? null)->toArray()));
    }

    public function suspend(Request $request, string $id): HttpResponse
    {
        return $this->guard(function () use ($request, $id): HttpResponse {
            $reason = self::body($request)['reason'] ?? '';

            return new JsonResponse($this->orchestrator->workflows()->suspend($id, is_string($reason) ? $reason : '')->toArray());
        });
    }

    public function resume(string $id): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => new JsonResponse($this->orchestrator->workflows()->resume($id)->toArray()));
    }

    public function cancel(string $id): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => new JsonResponse($this->orchestrator->workflows()->cancel($id)->toArray()));
    }

    public function query(string $id, string $query): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => new JsonResponse(['result' => $this->orchestrator->workflows()->query($id, $query)]));
    }

    public function deadLetters(Request $request): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => new JsonResponse(['deadLetters' => array_map(
            static fn (DeadLetterEntry $entry): array => $entry->toArray(),
            $this->deadLetters->all(self::limit($request)),
        )]));
    }

    public function retryDeadLetter(string $id): HttpResponse
    {
        return $this->guard(fn (): HttpResponse => $this->started($this->retrier->retry($id)));
    }

    public function deleteDeadLetter(string $id): HttpResponse
    {
        return $this->guard(function () use ($id): HttpResponse {
            if (! $this->deadLetters->delete($id)) {
                throw new DeadLetterNotFoundException($id);
            }

            return new Response('', 204);
        });
    }

    private function started(ExecutionOutcome $outcome): HttpResponse
    {
        if (! $outcome->success) {
            return new JsonResponse($outcome->toArray(), 422);
        }

        return new JsonResponse($outcome->toArray(), 201, ['Location' => url($this->settings->rest->path.'/executions/'.rawurlencode($outcome->correlationId))]);
    }

    /** @param Closure(): HttpResponse $action */
    private function guard(Closure $action): HttpResponse
    {
        try {
            return $action();
        } catch (FireflyException $refused) {
            return new JsonResponse(['error' => $refused->getMessage(), 'code' => $refused->errorCode()], $refused->httpStatus());
        }
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw new OrchestrationException('The request body must be a JSON object.', 'ORCHESTRATION_BAD_REQUEST', 400);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @return array<string, mixed> */
    private static function input(Request $request): array
    {
        $input = self::body($request)['input'] ?? [];
        if (! is_array($input) || array_is_list($input) && $input !== []) {
            throw new OrchestrationException('"input" must be a JSON object.', 'ORCHESTRATION_BAD_REQUEST', 400);
        }

        /** @var array<string, mixed> $input */
        return $input;
    }

    /** @return array<string, string> */
    private static function headers(Request $request): array
    {
        $headers = self::body($request)['headers'] ?? [];
        if (! is_array($headers) || array_is_list($headers) && $headers !== []) {
            throw new OrchestrationException('"headers" must be a JSON object of strings.', 'ORCHESTRATION_BAD_REQUEST', 400);
        }

        $strings = [];
        foreach ($headers as $name => $value) {
            if (! is_scalar($value)) {
                throw new OrchestrationException('"headers" must be a JSON object of strings.', 'ORCHESTRATION_BAD_REQUEST', 400);
            }
            $strings[(string) $name] = (string) $value;
        }

        return $strings;
    }

    private static function limit(Request $request): int
    {
        $limit = $request->query('limit', '50');

        return is_numeric($limit) ? max(1, min(500, (int) $limit)) : 50;
    }
}
