<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

use Firefly\Admin\AdminEndpointReader;
use Firefly\Admin\AdminSettings;
use Firefly\Context\Scan\AppScan;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The single invokable behind every dashboard page.
 *
 * Each page is a view over one ActuatorEndpoint's payload, read in-process (see AdminEndpointReader). The
 * action's only jobs are to pick the page, collect its data, and hand both to Blade — the views hold no
 * logic beyond formatting, so the shapes here are the shapes the actuator actually returns.
 */
final readonly class AdminAction
{
    public function __construct(
        private AdminSettings $settings,
        private AdminEndpointReader $reader,
        private ViewFactory $views,
        private Container $container,
    ) {}

    public function __invoke(Request $request, string $page = ''): SymfonyResponse
    {
        $slug = trim($page, '/');
        $current = null;
        foreach (AdminPage::all() as $candidate) {
            if ($candidate->slug === $slug) {
                $current = $candidate;
            }
        }

        if ($current === null) {
            return new Response($this->render('missing', ['slug' => $slug]), 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        if ($current->requires !== null && ! $this->reader->has($current->requires)) {
            return new Response($this->render('unavailable', ['page' => $current]), 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        if ($slug === 'loggers' && $request->isMethod('POST')) {
            return $this->setLoggerLevel($request);
        }

        return new Response(
            $this->render($slug === '' ? 'overview' : $slug, $this->data($slug)),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /** @return array<string,mixed> */
    private function data(string $slug): array
    {
        /** @var array<string,mixed> $data */
        $data = match ($slug) {
            '' => [
                'health' => $this->payload('health'),
                'info' => $this->payload('info'),
                'beans' => $this->listOf('beans', 'beans'),
                'conditions' => $this->payload('conditions'),
                'mappings' => $this->listOf('mappings', 'mappings'),
                'bootMode' => AppScan::cachedFile($this->container, AppScan::ROUTES) !== null ? 'compiled' : 'scanned',
                'endpoints' => $this->reader->available(),
            ],
            'beans' => ['beans' => $this->listOf('beans', 'beans')],
            'conditions' => $this->payload('conditions') + ['positiveMatches' => [], 'negativeMatches' => []],
            'mappings' => ['mappings' => $this->listOf('mappings', 'mappings')],
            'scheduled' => ['tasks' => $this->listOf('scheduledtasks', 'tasks')],
            'metrics' => ['metrics' => $this->metrics()],
            'loggers' => $this->payload('loggers') + ['levels' => [], 'loggers' => []],
            'env' => ['env' => $this->flatten($this->subArray($this->payload('env'), 'firefly'), 'firefly')],
            default => [],
        };

        return $data;
    }

    /**
     * An endpoint's payload as a string-keyed array — the shape every actuator endpoint returns.
     *
     * @return array<string,mixed>
     */
    private function payload(string $id): array
    {
        /** @var array<string,mixed> $body */
        $body = $this->reader->read($id) ?? [];

        return $body;
    }

    /**
     * One list-valued key out of an endpoint's payload (e.g. beans => 'beans', mappings => 'mappings').
     *
     * @return array<mixed>
     */
    private function listOf(string $id, string $key): array
    {
        return $this->subArray($this->payload($id), $key);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<mixed>
     */
    private function subArray(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * The metrics index returns names only, so each name is read back for its measurements — N in-process
     * calls, which is the right trade for a dashboard and keeps MetricsEndpoint's contract untouched.
     *
     * @return list<array{name: string, measurements: array<mixed>}>
     */
    private function metrics(): array
    {
        $metrics = [];
        foreach ($this->subArray($this->payload('metrics'), 'names') as $name) {
            if (! is_string($name)) {
                continue;
            }

            $detail = $this->reader->read('metrics', [$name]);
            /** @var array<string,mixed> $detail */
            $detail = is_array($detail) ? $detail : [];

            $metrics[] = ['name' => $name, 'measurements' => $this->subArray($detail, 'measurements')];
        }

        return $metrics;
    }

    private function setLoggerLevel(Request $request): RedirectResponse
    {
        $name = $request->input('logger');
        $level = $request->input('level');

        if (is_string($name) && $name !== '' && is_string($level) && $level !== '') {
            $this->reader->write('loggers', [$name], ['level' => $level]);
        }

        return new RedirectResponse($this->settings->url('loggers'));
    }

    /**
     * Flattens the nested firefly.* config into dotted keys, which is how a developer looks a key up and how
     * every other part of the framework names one.
     *
     * @param  array<mixed>  $values
     * @return array<string,string>
     */
    private function flatten(array $values, string $prefix): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix.'.'.(string) $key;
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat = [...$flat, ...$this->flatten($value, $path)];

                continue;
            }
            $flat[$path] = $this->scalar($value);
        }

        ksort($flat);

        return $flat;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            is_array($value) => $value === [] ? '[]' : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            default => get_debug_type($value),
        };
    }

    /** @param array<string,mixed> $data */
    private function render(string $view, array $data): string
    {
        return $this->views->make('firefly-admin::'.$view, [
            ...$data,
            'settings' => $this->settings,
            'nav' => $this->nav(),
            'active' => $view === 'overview' ? '' : $view,
        ])->render();
    }

    /** @return list<AdminPage> */
    private function nav(): array
    {
        return array_values(array_filter(
            AdminPage::all(),
            fn (AdminPage $page): bool => $page->requires === null || $this->reader->has($page->requires),
        ));
    }
}
