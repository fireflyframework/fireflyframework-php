<?php

declare(strict_types=1);

use Firefly\Web\Tests\Support\LaravelMessagesBootTestCase;

uses(LaravelMessagesBootTestCase::class);

it('keeps Laravel\'s humanised sentences when firefly.validation.messages is laravel, still naming the constraint', function () {
    /** @var LaravelMessagesBootTestCase $this */
    $response = $this->postJson('/transfers', [
        'amount' => 250,
        'beneficiary' => ['street' => 'Calle Mayor 1', 'postcode' => ''],
    ]);

    $response->assertStatus(422);

    // Testbench ships Laravel's `lang/en/validation.php`, so this is the sentence a created application
    // produced before this release — the one an application chooses this style to keep.
    expect((array) $response->json('errors'))->toContain([
        'field' => 'beneficiary.postcode',
        'message' => 'The beneficiary.postcode field is required.',
        'constraint' => 'NotBlank',
        'rejectedValue' => '',
    ]);
});
