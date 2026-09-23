<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\MethodSecuredDocumentCapstoneTestCase;

uses(MethodSecuredDocumentCapstoneTestCase::class);

/**
 * THE DOCUMENT AND THE DISPATCHER, READ TWICE. Every assertion below is a pair: what `/openapi.json` says
 * about a path, and what the running application answers on it. A disagreement between the two is the only
 * failure of this feature that matters, and it cannot be produced here without being visible.
 */
it('requires the scheme on an action a #[PreAuthorize] protects, although every URL rule is permitAll', function () {
    /** @var MethodSecuredDocumentCapstoneTestCase $this */
    // The URL layer opens this path (`*` permitAll) and the dispatcher refuses it. An empty list from the
    // URL contributor must NOT erase the method contributor's requirement: SecurityModel publishes the
    // operation as public only when nothing at all requires anything.
    expect($this->operation('/api/doc-orders/{id}')['security'])->toBe([['bearerAuth' => ['orders.read']]]);

    $this->get('/api/doc-orders/7')->assertStatus(401);
});

it('leaves the security member off an action no rule refuses', function () {
    /** @var MethodSecuredDocumentCapstoneTestCase $this */
    expect(array_key_exists('security', $this->operation('/api/doc-orders')))->toBeFalse();

    $this->get('/api/doc-orders')->assertStatus(200);
});

it('requires the scheme on an action whose only rule is a #[PostAuthorize], which refuses after the call', function () {
    /** @var MethodSecuredDocumentCapstoneTestCase $this */
    // The scanner compiles `permitAll()` as the pre expression here, so a contributor that looked only at
    // that would publish this operation as public while MethodSecurityEvaluator::after() answered 401.
    expect($this->operation('/api/doc-orders/audit')['security'])->toBe([['bearerAuth' => []]]);

    $this->get('/api/doc-orders/audit')->assertStatus(401);
});

it('leaves the security member off an action whose only rule is a #[PostFilter], which narrows rather than refuses', function () {
    /** @var MethodSecuredDocumentCapstoneTestCase $this */
    expect(array_key_exists('security', $this->operation('/api/doc-orders/feed')))->toBeFalse();

    $this->get('/api/doc-orders/feed')->assertStatus(200);
});

it('leaves no published scheme unreferenced and names no scheme it did not publish', function () {
    /** @var MethodSecuredDocumentCapstoneTestCase $this */
    /** @var array<string, array<string, mixed>> $components */
    $components = $this->document()['components'];

    // An orphan in either direction is a lie a generated client acts on. This is the invariant the openapi
    // capstone asserts for the config-driven contributor; here it is asserted with a method-rule
    // contributor naming the schemes instead, which is the half that capstone cannot see.
    expect(array_keys($this->namedSchemes()))->toBe(array_keys($components['securitySchemes']));
});

it('serves the scope list as a JSON array, so the requirement is valid 3.1 on the wire', function () {
    /** @var MethodSecuredDocumentCapstoneTestCase $this */
    $body = $this->responseBody($this->get('/openapi.json')->assertStatus(200));

    expect($body)->toContain('"bearerAuth": []')
        ->and($body)->toContain('"orders.read"')
        ->and($body)->not->toContain('"bearerAuth": {}');
});
