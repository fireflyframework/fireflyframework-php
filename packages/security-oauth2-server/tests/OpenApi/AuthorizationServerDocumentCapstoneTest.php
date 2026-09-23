<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\AuthorizationServerDocumentCapstoneTestCase;

uses(AuthorizationServerDocumentCapstoneTestCase::class);

/**
 * One document, both contributors, no registered client — the shape that used to publish a dangling
 * reference.
 */
it('names no scheme it did not publish, and publishes none nothing names', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    // An orphan in either direction is a lie a generated client acts on: a scheme published but never
    // required offers a caller an option no path declares usable, and a scheme required but never published
    // is a dangling reference no tooling can resolve. SecurityModel::resolve() leaves an unknown name
    // exactly as written, on purpose, so the second kind cannot be caught anywhere but here.
    expect($this->namedSchemes())->toBe($this->publishedSchemes());
});

it('publishes the authorizationCode flow this server really serves, with an empty scopes map while no client has registered one', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = $this->document()['components'];
    $scheme = $components['securitySchemes']['oauth2AuthorizationCode'];

    expect($scheme['type'])->toBe('oauth2')
        ->and($scheme['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'http://localhost/oauth2/authorize',
                'tokenUrl' => 'http://localhost/oauth2/token',
                'scopes' => [],
            ],
        ]);
});

it('names the authorization server\'s scheme beside the resource server\'s on a method-secured operation', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = $this->document()['paths'];

    // Both credentials really do get a caller in — a token obtained through the flow this server publishes
    // is presented to the resource-server filter as a bearer — so the OR-list names both, in the order the
    // contributors were asked (the URL rules first).
    //
    // AND EACH SCHEME APPEARS ONCE, CARRYING THE UNION. The URL rule here is `authenticated`, so
    // ConfiguredSecurity asks for `oauth2ResourceServer` with NO scopes, while the #[PreAuthorize] asks for
    // `hasScope('orders.read')`. Published as two entries, the scopeless one would satisfy the operation on
    // its own under OpenAPI's OR reading and the scope would mean nothing; the mechanisms are conjunctive at
    // runtime, so the merged entry is the true one.
    expect($paths['/api/doc-orders/{id}']['get']['security'])->toBe([
        ['oauth2ResourceServer' => ['orders.read']],
        ['oauth2AuthorizationCode' => ['orders.read']],
    ]);
});

it('serves an empty scopes map as a JSON object, so the Flow Object stays valid 3.1', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    $body = $this->responseBody($this->get('/openapi.json')->assertStatus(200));

    expect($body)->toContain('"scopes": {}')
        ->and($body)->not->toContain('"scopes": []');
});
