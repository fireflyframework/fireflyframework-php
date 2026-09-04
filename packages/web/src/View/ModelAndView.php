<?php

declare(strict_types=1);

namespace Firefly\Web\View;

/**
 * Spring's ModelAndView: a view NAME plus the model that populates it, resolved to a real view by
 * ResponseFactory through the application's view factory.
 *
 * Returning `view('welcome', [...])` from a controller works too and is the idiomatic Laravel spelling. This
 * exists for the case where a controller should not reach for the `view()` helper — a handler under test, or
 * one in a package that must not depend on illuminate/view — and for parity with the Spring shape the rest of
 * the framework mirrors.
 *
 * A bare string return is deliberately NOT treated as a view name. #[RestController] methods legitimately
 * return strings that must negotiate to JSON, and there is no way to tell the two apart at dispatch without
 * making the meaning of a return value depend on its declaring class. Explicit beats magic.
 */
final readonly class ModelAndView
{
    /**
     * @param  array<string,mixed>  $model
     * @param  array<string,string>  $headers
     */
    private function __construct(
        public string $view,
        public array $model = [],
        public int $status = 200,
        public array $headers = [],
    ) {}

    /**
     * @param  array<string,mixed>  $model
     */
    public static function of(string $view, array $model = []): self
    {
        return new self($view, $model);
    }

    public function withStatus(int $status): self
    {
        return new self($this->view, $this->model, $status, $this->headers);
    }

    /**
     * @param  array<string,mixed>  $model
     */
    public function withModel(array $model): self
    {
        return new self($this->view, [...$this->model, ...$model], $this->status, $this->headers);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->view, $this->model, $this->status, [...$this->headers, $name => $value]);
    }
}
