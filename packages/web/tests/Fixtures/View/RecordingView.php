<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\View;

use Illuminate\Contracts\View\View as ViewContract;

/** The View a RecordingViewFactory produces: renders its own name plus the model keys it was given. */
final class RecordingView implements ViewContract
{
    /** @param array<string,mixed> $data */
    public function __construct(private readonly string $view, private array $data = []) {}

    public function name(): string
    {
        return $this->view;
    }

    /** @return array<string,mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function with($key, $value = null): self
    {
        $this->data[(string) $key] = $value;

        return $this;
    }

    public function render(): string
    {
        return $this->view.':'.implode(',', array_keys($this->data));
    }
}
