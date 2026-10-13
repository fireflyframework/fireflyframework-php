<?php

declare(strict_types=1);

use Firefly\Orchestration\Http\OrchestrationController;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Tests\CapstoneFixtures\Ledger;
use Firefly\Orchestration\Tests\Support\RestCapstoneTestCase;
use Firefly\Orchestration\Tests\Support\WithholdingPersistence;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

uses(RestCapstoneTestCase::class);

/**
 * Narrowed rather than merely asserted: the id is threaded into the URLs that follow, and a missing one would
 * otherwise surface as a confusing 404 instead of "the response carried no id".
 *
 * @param  TestResponse<Response>  $response
 */
function restId(TestResponse $response, string $path): string
{
    $id = $response->json($path);
    if (! is_string($id) || $id === '') {
        throw new RuntimeException("the response carried no string [{$path}].");
    }

    return $id;
}

it('starts a saga and points at its checkpoint', function (): void {
    /** @var RestCapstoneTestCase $this */
    $response = $this->postJson('/ops/orchestration/sagas/checkout', ['input' => ['orderId' => 10, 'amount' => 5]])
        ->assertCreated()
        ->assertJson(['pattern' => 'SAGA', 'name' => 'checkout', 'status' => 'COMPLETED', 'success' => true]);
    $id = restId($response, 'correlationId');

    expect($response->headers->get('Location'))->toEndWith("/ops/orchestration/executions/{$id}");
    $this->getJson("/ops/orchestration/executions/{$id}")->assertOk()->assertJson(['correlationId' => $id, 'status' => 'COMPLETED']);
    $this->getJson('/ops/orchestration/executions?pattern=saga')->assertOk()->assertJsonPath('executions.0.correlationId', $id);
    $this->getJson('/ops/orchestration/executions/nope')->assertNotFound()->assertJson(['code' => 'ORCHESTRATION_EXECUTION_NOT_FOUND']);
});

it('answers 422 for a failed saga and retries and deletes its dead letter', function (): void {
    /** @var RestCapstoneTestCase $this */
    $this->postJson('/ops/orchestration/sagas/checkout', ['input' => ['orderId' => 11, 'amount' => 500]])
        ->assertStatus(422)
        ->assertJson(['status' => 'FAILED', 'success' => false]);

    $id = restId($this->getJson('/ops/orchestration/dlq')->assertOk(), 'deadLetters.0.id');

    $this->postJson("/ops/orchestration/dlq/{$id}/retry")->assertStatus(422)->assertJson(['status' => 'FAILED']);
    $this->deleteJson("/ops/orchestration/dlq/{$id}")->assertNotFound();

    // The retry removed the entry it retried and the failed re-run dead-lettered itself under a fresh id.
    $retried = restId($this->getJson('/ops/orchestration/dlq')->assertOk()->assertJsonCount(1, 'deadLetters'), 'deadLetters.0.id');
    expect($retried)->not->toBe($id);

    $this->deleteJson("/ops/orchestration/dlq/{$retried}")->assertNoContent();
    $this->deleteJson("/ops/orchestration/dlq/{$retried}")->assertNotFound();
    $this->postJson("/ops/orchestration/dlq/{$retried}/retry")->assertNotFound();
});

it('confirms a TCC', function (): void {
    /** @var RestCapstoneTestCase $this */
    $this->postJson('/ops/orchestration/tcc/transfer', ['input' => ['amount' => 25, 'to' => 'acc-2']])
        ->assertCreated()
        ->assertJson(['pattern' => 'TCC', 'status' => 'CONFIRMED']);

    expect($this->app()->make(Ledger::class)->lines)->toContain('commit hold-25');
});

it('drives a workflow through signals, queries, suspension and cancellation', function (): void {
    /** @var RestCapstoneTestCase $this */
    $this->postJson('/ops/orchestration/workflows/onboarding', ['input' => ['email' => 'ada@example.com'], 'correlationId' => 'rest-1'])
        ->assertCreated()
        ->assertJson(['correlationId' => 'rest-1', 'pattern' => 'WORKFLOW', 'status' => 'WAITING']);

    $this->getJson('/ops/orchestration/workflows/rest-1/queries/progress')->assertOk()->assertExactJson(['result' => 1]);
    $this->postJson('/ops/orchestration/workflows/rest-1/suspend', ['reason' => 'audit'])->assertOk()->assertJson(['status' => 'SUSPENDED']);
    $this->postJson('/ops/orchestration/workflows/rest-1/resume')->assertOk()->assertJson(['status' => 'WAITING', 'waitingOn' => 'verify']);
    $this->postJson('/ops/orchestration/workflows/rest-1/signals/email-verified', ['payload' => ['token' => 't']])
        ->assertOk()
        ->assertJson(['status' => 'WAITING', 'waitingOn' => 'cool-off']);
    $this->postJson('/ops/orchestration/workflows/rest-1/cancel')->assertOk()->assertJson(['status' => 'CANCELLED']);
    $this->postJson('/ops/orchestration/workflows/rest-1/cancel')->assertStatus(409);
    $this->postJson('/ops/orchestration/workflows/nope/signals/email-verified')->assertNotFound();
});

it('refuses bodies that are not JSON objects and unknown definitions', function (): void {
    /** @var RestCapstoneTestCase $this */
    $this->postJson('/ops/orchestration/sagas/checkout', ['input' => 'nope'])->assertStatus(400)->assertJson(['code' => 'ORCHESTRATION_BAD_REQUEST']);
    $this->postJson('/ops/orchestration/sagas/nope', ['input' => []])->assertNotFound();
});

it('answers a refusal that withholds its sentence without the raw cause its message quotes', function (): void {
    /** @var RestCapstoneTestCase $this */
    $this->app()->instance(ExecutionPersistenceProvider::class, new WithholdingPersistence);

    $response = $this->getJson('/ops/orchestration/executions/e-1')
        ->assertStatus(500)
        ->assertExactJson(['error' => OrchestrationController::WITHHELD, 'code' => 'ORCHESTRATION_STORE_FAILED']);

    expect((string) $response->getContent())->not->toContain('10.0.0.7');
});
