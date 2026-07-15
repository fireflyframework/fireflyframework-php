<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Processor\BeanPostProcessor;

/**
 * The HIGH-#[Order] half of the two-BPP chain applied to PostConstructWidget. Its
 * afterInitialization() REPLACES the bean with a PostConstructWidgetProxy — proving invariant 3/4
 * end-to-end: ordering and the wrapping decision are both keyed on $declaredClass (the MANIFEST's
 * declared class), never on $bean::class, which would already be wrong for any bean a prior BPP in
 * the chain had replaced.
 */
#[Component]
#[Order(2)]
final class ProxyingBpp implements BeanPostProcessor
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        if ($declaredClass === PostConstructWidget::class) {
            $this->recorder->record('bpp:before:2');
        }

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        if ($declaredClass !== PostConstructWidget::class) {
            return $bean;
        }

        $this->recorder->record('bpp:after:2');

        /** @var PostConstructWidget $bean */
        return new PostConstructWidgetProxy($bean);
    }
}
