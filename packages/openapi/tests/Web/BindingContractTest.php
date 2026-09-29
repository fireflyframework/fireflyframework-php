<?php

declare(strict_types=1);

use Firefly\OpenApi\Schema\ProblemSchema;
use Firefly\OpenApi\Tests\Support\BindingContractTestCase;
use Firefly\OpenApi\Tests\Support\FixtureDocument;

pest()->extend(BindingContractTestCase::class);

it('does not promise validation for a Valid query, service or untyped body that dispatch never validates', function (string $path, string $verb, array $input) {
    /** @var BindingContractTestCase $this */
    if ($verb === 'get') {
        $this->getJson($path.'?'.http_build_query($input))->assertOk();
    } else {
        $this->postJson($path, $input)->assertOk();
    }
    $operation = FixtureDocument::operation(FixtureDocument::generatorFor('BindingFixture')->generate(), $path, $verb);

    expect($operation['responses'])->not->toHaveKey('422');
})->with([
    'query' => ['/binding/query', 'get', ['value' => 'accepted']],
    'service' => ['/binding/service', 'get', []],
    'untyped body' => ['/binding/untyped', 'post', ['value' => 'accepted']],
]);

it('publishes the problem response for the path-pattern failure the dispatcher produces', function () {
    /** @var BindingContractTestCase $this */
    $this->getJson('/binding/pattern/not-a-uuid')->assertNotFound()->assertJsonPath('code', 'ITEM_NOT_FOUND');
    $operation = FixtureDocument::operation(FixtureDocument::generatorFor('BindingFixture')->generate(), '/binding/pattern/{id}', 'get');

    expect($operation['responses'])->toHaveKey('404')
        ->and(data_get($operation, 'responses.404'))->toBe(['$ref' => ProblemSchema::RESPONSE_REF]);
});

it('does not invent a pattern failure for an unconstrained path parameter', function () {
    /** @var BindingContractTestCase $this */
    $this->getJson('/binding/plain/anything')->assertOk();
    $operation = FixtureDocument::operation(FixtureDocument::generatorFor('BindingFixture')->generate(), '/binding/plain/{id}', 'get');

    expect($operation['responses'])->not->toHaveKey('404');
});

it('preserves declared response prose over a derived pattern failure', function () {
    $operation = FixtureDocument::operation(FixtureDocument::generatorFor('BindingFixture')->generate(), '/binding/declared/{id}', 'get');

    expect(data_get($operation, 'responses.404'))->toMatchArray([
        'description' => 'This identifier does not name an item.',
        'content' => [ProblemSchema::MEDIA_TYPE => ['schema' => ['$ref' => ProblemSchema::REF]]],
    ]);
});

it('derives an included page controllers media type from its return contract', function () {
    /** @var BindingContractTestCase $this */
    $this->getJson('/binding/page-data')->assertOk()->assertJsonPath('message', 'data from a page controller');
    $document = FixtureDocument::generatorFor('BindingFixture', FixtureDocument::properties(includeHtml: true))->generate();
    $operation = FixtureDocument::operation($document, '/binding/page-data', 'get');

    expect(data_get($operation, 'responses.200.content'))->toHaveKey('application/json')
        ->not->toHaveKey('text/html')
        ->and(data_get($operation, 'responses.200.content.application/json.schema.properties'))->toHaveKey('message');
});

it('keeps actual markup and redirect responses for included page controllers', function () {
    /** @var BindingContractTestCase $this */
    $this->get('/binding/page-markup')->assertOk()->assertSee('<h1>A page</h1>', false);
    $this->get('/binding/page-redirect')->assertRedirect('/binding/page-markup');
    $document = FixtureDocument::generatorFor('BindingFixture', FixtureDocument::properties(includeHtml: true))->generate();
    $markup = FixtureDocument::operation($document, '/binding/page-markup', 'get');
    $redirect = FixtureDocument::operation($document, '/binding/page-redirect', 'get');

    expect(data_get($markup, 'responses.200.content'))->toHaveKey('text/html')
        ->and($redirect['responses'])->toHaveKey('302')
        ->and(data_get($redirect, 'responses.302.headers'))->toHaveKey('Location');
});
