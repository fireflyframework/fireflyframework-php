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

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $mergeData
     */
    public function file($path, $data = [], $mergeData = []): ViewContract
    {
        return $this->make($path, $data, $mergeData);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $mergeData
     */
    public function make($view, $data = [], $mergeData = []): ViewContract
    {
        /** @var array<string,mixed> $data */
        return new RecordingView((string) $view, $data);
    }

    /** @param array<string,mixed>|string $key */
    public function share($key, $value = null): mixed
    {
        return $value;
    }

    /**
     * @param  array<int,string>|string  $views
     * @return array<int,mixed>
     */
    public function composer($views, $callback): array
    {
        return [];
    }

    /**
     * @param  array<int,string>|string  $views
     * @return array<int,mixed>
     */
    public function creator($views, $callback): array
    {
        return [];
    }

    /** @param array<int,string>|string $hints */
    public function addNamespace($namespace, $hints): self
    {
        return $this;
    }

    /** @param array<int,string>|string $hints */
    public function replaceNamespace($namespace, $hints): self
    {
        return $this;
    }
}
