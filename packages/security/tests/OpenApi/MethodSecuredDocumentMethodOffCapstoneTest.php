<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\MethodSecuredDocumentMethodOffCapstoneTestCase;

uses(MethodSecuredDocumentMethodOffCapstoneTestCase::class);

/**
 * `firefly.security.method.enabled: false` stands the PROXY LINK down and nothing else. The pairing below is
 * the whole point: the document still requires the credential, and the dispatcher still refuses the caller.
 */
it('still publishes the requirement with method security switched off, because the dispatcher still enforces it', function () {
    /** @var MethodSecuredDocumentMethodOffCapstoneTestCase $this */
    expect($this->operation('/api/doc-orders/{id}')['security'])->toBe([['bearerAuth' => ['orders.read']]]);

    $this->get('/api/doc-orders/7')->assertStatus(401);
});

it('still publishes the requirement a #[PostAuthorize] carries, and still refuses the caller', function () {
    /** @var MethodSecuredDocumentMethodOffCapstoneTestCase $this */
    expect($this->operation('/api/doc-orders/audit')['security'])->toBe([['bearerAuth' => []]]);

    $this->get('/api/doc-orders/audit')->assertStatus(401);
});
