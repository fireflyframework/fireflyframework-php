<?php

declare(strict_types=1);

use Firefly\Web\Tests\Support\UncachedBootTestCase;

uses(UncachedBootTestCase::class);

/**
 * The 422 a client actually receives, on the boot a real application takes: worded by the constraint, naming
 * the constraint, keyed by the path the client sent — and never by Laravel's humanised attribute.
 */
it('words each field error by its constraint and names it, with the path the client sent', function () {
    /** @var UncachedBootTestCase $this */
    $response = $this->postJson('/transfers', [
        'amount' => 250,
        'beneficiary' => ['street' => '', 'postcode' => ''],
    ]);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $errors = (array) $response->json('errors');

    expect($errors)->toContain(['field' => 'beneficiary.street', 'message' => 'must not be blank', 'constraint' => 'NotBlank', 'rejectedValue' => ''])
        ->toContain(['field' => 'beneficiary.postcode', 'message' => 'must not be blank', 'constraint' => 'NotBlank', 'rejectedValue' => ''])
        ->and(json_encode(array_column($errors, 'message')))->not->toContain('field is required');
});
