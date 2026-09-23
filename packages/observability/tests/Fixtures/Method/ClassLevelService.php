<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Method;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Counted;
use Firefly\Observability\Method\Timed;

/**
 * Micrometer's class-level shape: one #[Timed] on the class times EVERY public method, and a method-level
 * #[Timed] REPLACES it whole for its own method — the replacement is total, never a merge, so the class's
 * `extraTags` do not leak onto a method that named its own meter. A method carrying only #[Counted] keeps the
 * inherited timer beside its own counter, because the three kinds are resolved independently.
 *
 * The two methods the fan-out must NOT reach are here for the same reason: a proxy cannot intercept a static
 * call (there is no instance to wrap) and the `__`-prefixed magic methods are the ones the proxy itself
 * relies on, so both are skipped before the attributes are even read.
 */
#[Service]
#[Timed('orders.svc', extraTags: ['scope' => 'class'], description: 'Every order operation.')]
class ClassLevelService
{
    public function inherited(): void {}

    #[Timed('orders.method')]
    public function overridden(): void {}

    #[Counted('orders.counted')]
    public function alsoCounted(): void {}

    public static function skipped(): void {}

    public function __invoke(): void {}
}
