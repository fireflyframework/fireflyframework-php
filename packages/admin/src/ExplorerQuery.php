<?php

declare(strict_types=1);

namespace Firefly\Admin;

use Illuminate\Http\Request;

final readonly class ExplorerQuery
{
    /** @param array<string,string> $parameters */
    public function __construct(public string $path, public array $parameters, public int $depth, public string $direction) {}

    public static function fromRequest(Request $request, string $path, BeanGraphSettings $settings): self
    {
        $parameters = [];
        foreach ($request->query() as $key => $value) {
            if (is_string($key) && is_string($value) && strlen($value) <= 1024) {
                $parameters[$key] = $value;
            }
        }
        $raw = $parameters['depth'] ?? '';
        $depth = ctype_digit($raw) ? min(4, max(1, (int) $raw)) : $settings->depth;
        $direction = in_array($parameters['dir'] ?? '', ['in', 'out', 'both'], true) ? $parameters['dir'] : 'both';
        $parameters['depth'] = (string) $depth;
        $parameters['dir'] = $direction;

        return new self($path, $parameters, $depth, $direction);
    }

    public function get(string $key): string
    {
        return $this->parameters[$key] ?? '';
    }

    /** @param array<string,string|int|null> $changes */
    public function url(array $changes = []): string
    {
        $parameters = array_filter([...$this->parameters, ...$changes], static fn (string|int|null $value): bool => $value !== null && $value !== '');

        return $this->path.($parameters === [] ? '' : '?'.http_build_query($parameters));
    }

    /** Parameters owned by other controls only.
     * @return array<string,string> */
    public function carried(string $qualifier): array
    {
        $parameters = $this->parameters;
        foreach (['page', 'size', 'sort', 'dir', 'q'] as $key) {
            unset($parameters[$qualifier.'_'.$key]);
        }

        return $parameters;
    }

    public function bean(string $id): string
    {
        return $this->url(['bean' => $id, 'module' => null, 'q' => null, 'neighbor' => null, 'rel' => null, 'in_page' => null, 'out_page' => null]);
    }
}
