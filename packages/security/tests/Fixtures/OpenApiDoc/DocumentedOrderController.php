<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\OpenApiDoc;

use Firefly\Security\Access\Attributes\PostAuthorize;
use Firefly\Security\Access\Attributes\PostFilter;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\RestController;

/**
 * The four shapes the OpenAPI document has to tell apart, on routes the URL rules deliberately leave WIDE
 * OPEN — the method-security-first setup (`anyRequest().permitAll()` plus rules on the handlers) this whole
 * seam exists to describe. What protects three of these actions is the dispatcher, and only the compiled
 * method-security manifest knows it.
 */
#[RestController]
final class DocumentedOrderController
{
    /** @return list<string> */
    #[GetMapping('/api/doc-orders')]
    public function index(): array
    {
        return [];
    }

    /**
     * Only a #[PostAuthorize]: the scanner compiles `permitAll()` as the pre expression, and the evaluator
     * still refuses an anonymous caller after the call.
     *
     * @return list<string>
     */
    #[PostAuthorize("hasRole('ADMIN')")]
    #[GetMapping('/api/doc-orders/audit')]
    public function audit(): array
    {
        return [];
    }

    /**
     * Only a #[PostFilter]: it narrows the result and refuses nobody, so this action really is public.
     *
     * @return list<string>
     */
    #[PostFilter("hasRole('ADMIN')")]
    #[GetMapping('/api/doc-orders/feed')]
    public function feed(): array
    {
        return [];
    }

    /**
     * The scope written as the AUTHORITY the evaluator normalises a bare one to — the spelling
     * SecurityExpressionRoot::hasScope() documents, and an identical rule to `hasScope('orders.write')`. The
     * document has to publish the OAUTH2 SCOPE, `orders.write`: a requirement's scope list is what a client
     * asks the authorization server for, and no registration holds a scope named `SCOPE_orders.write`.
     *
     * @return list<string>
     */
    #[PreAuthorize("hasScope('SCOPE_orders.write')")]
    #[GetMapping('/api/doc-orders/drafts')]
    public function drafts(): array
    {
        return [];
    }

    /**
     * Declared LAST because the router matches in registration order and `{id}` would otherwise swallow the
     * three literal paths above.
     *
     * @return array{id: string}
     */
    #[PreAuthorize("hasScope('orders.read')")]
    #[GetMapping('/api/doc-orders/{id}')]
    public function show(#[PathVariable] string $id): array
    {
        return ['id' => $id];
    }
}
