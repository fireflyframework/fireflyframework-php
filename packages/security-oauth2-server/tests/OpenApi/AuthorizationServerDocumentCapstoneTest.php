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

it('publishes the authorizationCode flow this server really serves, declaring the scope its own operations demand although no client has registered one', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    /** @var array<string, array<string, array<string, mixed>>> $components */
    $components = $this->document()['components'];
    $scheme = $components['securitySchemes']['oauth2AuthorizationCode'];

    // THE SECOND ORPHAN, and the one only this capstone can see: the client registry is empty, so this
    // package's contributor has no scope to publish, while firefly/security's #[PreAuthorize] contributor
    // puts `orders.read` on this very scheme two paths below. Published as it was stated, the document would
    // require a scope its own Flow Object does not define — the Authorize dialog would offer nothing to ask
    // for, and Spectral's oas3-operation-security-defined would reject the operation. SecurityModel holds
    // both lists and is the only thing that can join them.
    expect($scheme['type'])->toBe('oauth2')
        ->and($scheme['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'http://localhost/oauth2/authorize',
                'tokenUrl' => 'http://localhost/oauth2/token',
                // Described with the name itself: the model has no vocabulary of its own, and a scope a
                // client DID register would carry the consent page's sentence instead.
                //
                // `orders.write` arrives from a rule SPELLED `hasScope('SCOPE_orders.write')`, the authority
                // form the evaluator normalises a bare scope to. Declared as written it would put
                // `SCOPE_orders.write` in this very map, and the Authorize dialog would ask this server for a
                // scope no client registration can hold — the over-statement that costs the authorization
                // request, arriving through the scheme the document itself publishes.
                'scopes' => ['orders.read' => 'orders.read', 'orders.write' => 'orders.write'],
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

it('serves the scopes map as a JSON object, so the Flow Object stays valid 3.1', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    $body = $this->responseBody($this->get('/openapi.json')->assertStatus(200));

    // A Flow Object's `scopes` is typed as a MAP by the 3.1 meta-schema, so `"scopes": []` is a document a
    // strict validator rejects — which is what an empty PHP array serialises to without the generator's
    // objectify() (pinned on an empty map of its own in packages/openapi's SecuritySchemesGeneratorTest).
    expect($body)->toContain('"scopes": {')
        ->and($body)->toContain('"orders.read": "orders.read"')
        ->and($body)->not->toContain('"scopes": []');
});

it('requires of an operation no scope its own securitySchemes does not declare', function () {
    /** @var AuthorizationServerDocumentCapstoneTestCase $this */
    $document = $this->document();

    $components = $document['components'];
    $schemes = is_array($components) && is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];
    $paths = is_array($document['paths']) ? $document['paths'] : [];

    // The invariant rather than the example, over EVERY operation of the document: this is the one an
    // OpenAPI linter enforces, and the one the two-contributor split can break again from either side.
    foreach ($paths as $path => $operations) {
        foreach (is_array($operations) ? $operations : [] as $verb => $operation) {
            if (! is_array($operation) || ! is_array($operation['security'] ?? null)) {
                continue;
            }

            foreach ($operation['security'] as $entry) {
                foreach (is_array($entry) ? $entry : [] as $scheme => $scopes) {
                    $definition = $schemes[$scheme] ?? null;

                    if (! is_array($definition) || ($definition['type'] ?? null) !== 'oauth2') {
                        continue;
                    }

                    expect($definition['flows'])->toBeArray();

                    $required = array_filter(is_array($scopes) ? $scopes : [], 'is_string');

                    foreach (is_array($definition['flows']) ? $definition['flows'] : [] as $flow => $flowed) {
                        expect($flowed)->toBeArray()->toHaveKey('scopes');

                        $declared = is_array($flowed) && is_array($flowed['scopes']) ? array_keys($flowed['scopes']) : [];

                        expect(array_diff($required, $declared))
                            ->toBe([], "{$verb} {$path} requires a scope {$scheme}.{$flow} does not declare");
                    }
                }
            }
        }
    }
});
