<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Security;

/**
 * The query-side authorization SEAM (interface only in M10), twin of CommandAuthorizer. AllowAllAuthorizer is the
 * shipped no-op default; M11 supplies real evaluation.
 */
interface QueryAuthorizer
{
    public function authorize(object $query): void;
}
