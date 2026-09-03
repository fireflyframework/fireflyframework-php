<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\View;

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Contracts\View\View as ViewContract;

/**
 * A minimal Illuminate view factory that renders "<name>:<comma-separated model keys>", so a test can assert
 * ResponseFactory resolved the right view name with the right model without pulling in illuminate/view.
 */
final class RecordingViewFactory implements ViewFactoryContract
{
    public function exists($view): bool
    {
        return true;
    }

    public function file($path, $data = [], $mergeData = []): ViewContract
    {
        return $this->make($path, $data, $mergeData);
    }

    public function make($view, $data = [], $mergeData = []): ViewContract
    {
        /** @var array<string,mixed> $data */
        return new RecordingView((string) $view, $data);
    }

    public function share($key, $value = null): mixed
    {
        return $value;
    }

    /** @return array<int,mixed> */
    public function composer($views, $callback): array
    {
        return [];
    }

    /** @return array<int,mixed> */
    public function creator($views, $callback): array
    {
        return [];
    }

    public function addNamespace($namespace, $hints): self
    {
        return $this;
    }

    public function replaceNamespace($namespace, $hints): self
    {
        return $this;
    }
}
