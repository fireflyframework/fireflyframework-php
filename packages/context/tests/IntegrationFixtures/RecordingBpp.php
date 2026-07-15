<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\IntegrationFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Processor\BeanPostProcessor;

/**
 * The LOW-#[Order] half of the two-BPP chain applied to PostConstructWidget. Records only for that
 * one declared class (ignoring every other bean in the manifest, which this composite extender
 * also runs against) so IntegrationTest's recorded trace stays a clean, single-purpose proof of
 * ordering rather than noise from the whole fixture set.
 */
#[Component]
#[Order(1)]
final class RecordingBpp implements BeanPostProcessor
{
    public function __construct(private readonly WidgetRecorder $recorder) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        if ($declaredClass === PostConstructWidget::class) {
            $this->recorder->record('bpp:before:1');
        }

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        if ($declaredClass === PostConstructWidget::class) {
            $this->recorder->record('bpp:after:1');
        }

        return $bean;
    }
}
