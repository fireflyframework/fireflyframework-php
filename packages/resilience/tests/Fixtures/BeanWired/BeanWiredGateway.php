<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\BeanWired;

use Firefly\Resilience\Method\Retry;

/**
 * The shape a stereotype-only refusal would reject: a concrete class with NO stereotype, wired by the
 * #[Bean] method beside it because its constructor takes an argument the container cannot autowire. The
 * bean-post-processor chain is installed on the #[Bean] method's declared return type, so this class IS
 * post-processed and IS proxied — exactly as #[Transactional] on such a class is today — and its retry does
 * run.
 */
class BeanWiredGateway
{
    public function __construct(private readonly string $endpoint) {}

    #[Retry('payments')]
    public function charge(int $cents): string
    {
        return $this->endpoint.':'.$cents;
    }
}
