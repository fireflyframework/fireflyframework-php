<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

use Firefly\Admin\AdminEndpointReader;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\Format;
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
 * Each page is a view over one ActuatorEndpoint's payload, read in-process (see AdminEndpointReader). This
 * class picks the page, collects its data in the shape the view wants, and renders — the views hold no logic
 * beyond formatting, so the array shapes here are the shapes the actuator actually returns.
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
            return $this->html($this->render('missing', ['slug' => $slug]), 404);
        }

        if ($current->requires !== null && ! $this->reader->has($current->requires)) {
            return $this->html($this->render('unavailable', ['page' => $current]), 404);
        }

        if ($slug === 'loggers' && $request->isMethod('POST')) {
            return $this->setLoggerLevel($request);
        }

        return $this->html($this->render($slug === '' ? 'overview' : $slug, $this->data($slug), $current), 200);
    }

    /** @return array<string,mixed> */
    private function data(string $slug): array
    {
        return match ($slug) {
            '' => $this->overview(),
            'health' => ['indicators' => $this->reader->healthIndicators(), 'aggregate' => $this->aggregateStatus()],
            'metrics' => ['metrics' => $this->metrics()],
            'http' => ['exchanges' => $this->exchanges()],
            'beans' => ['beans' => $this->listOf('beans', 'beans')],
            'conditions' => $this->payload('conditions') + ['positiveMatches' => [], 'negativeMatches' => []],
            'mappings' => ['mappings' => $this->listOf('mappings', 'mappings')],
            'scheduled' => ['tasks' => $this->listOf('scheduledtasks', 'tasks')],
            'env' => ['env' => $this->flatten($this->subArray($this->payload('env'), 'firefly'), 'firefly')],
            'configprops' => ['contexts' => $this->payload('configprops')],
            'caches' => ['caches' => $this->payload('caches')],
            'loggers' => $this->payload('loggers') + ['levels' => [], 'loggers' => []],
            default => [],
        };
    }

    /**
     * The overview is the page an operator leaves open, so it answers the three questions that matter
     * without a click: is it healthy, what is it doing, and what did it wire.
     *
     * @return array<string,mixed>
     */
    private function overview(): array
    {
        $indicators = $this->reader->healthIndicators();
        $conditions = $this->payload('conditions');

        return [
            'aggregate' => $this->aggregateStatus(),
            'indicators' => $indicators,
            'info' => $this->runtime(),
            'beans' => $this->listOf('beans', 'beans'),
            'mappings' => $this->listOf('mappings', 'mappings'),
            'positive' => $this->subArray($conditions, 'positiveMatches'),
            'negative' => $this->subArray($conditions, 'negativeMatches'),
            'tasks' => $this->listOf('scheduledtasks', 'tasks'),
            'metrics' => $this->metrics(),
            'exchanges' => array_slice($this->exchanges(), 0, 8),
            'bootMode' => AppScan::cachedFile($this->container, AppScan::ROUTES) !== null ? 'compiled' : 'scanned',
            'endpoints' => $this->reader->available(),
        ];
    }

    /**
     * /actuator/info flattened to dotted keys and formatted for reading.
     *
     * Contributors publish nested maps, so rendering the top level only produced cells containing raw JSON
     * — `{"used":2097152,"peak":2097152}` where an operator wants `2.0 MB`. Flattening gives one row per
     * fact, and the byte-ish keys the runtime contributor uses are formatted as sizes.
     *
     * @return array<string,string>
     */
    private function runtime(): array
    {
        $flat = $this->flatten($this->payload('info'), 'info');

        $rows = [];
        foreach ($flat as $key => $value) {
            $leaf = substr($key, strrpos($key, '.') + 1);
            $rows[substr($key, strlen('info.'))] = is_numeric($value)
                ? Format::detail($leaf, $value + 0)
                : $value;
        }

        return $rows;
    }

    /**
     * The worst status any indicator reports — the same aggregation the health endpoint performs, computed
     * here so the page shows a status even when the endpoint withholds its components.
     */
    private function aggregateStatus(): string
    {
        $body = $this->payload('health');
        $status = $body['status'] ?? null;
        if (is_string($status) && $status !== '') {
            return $status;
        }

        $worst = 'UNKNOWN';
        foreach ($this->reader->healthIndicators() as $indicator) {
            if ($indicator['status'] === 'DOWN') {
                return 'DOWN';
            }
            if ($indicator['status'] === 'UP' && $worst === 'UNKNOWN') {
                $worst = 'UP';
            }
        }

        return $worst;
    }

    /**
     * The metrics index returns names only, so each name is read back for its measurements — N in-process
     * calls, the right trade for a dashboard, and it keeps MetricsEndpoint's contract untouched.
     *
     * Each measurement is pre-formatted here (bytes as MB, seconds as ms) because the view must not be
     * doing arithmetic, and the JSON surface must keep returning raw numbers for Prometheus.
     *
     * @return list<array{name: string, rows: list<array{statistic: string, value: float, display: string}>}>
     */
    private function metrics(): array
    {
        $metrics = [];
        foreach ($this->subArray($this->payload('metrics'), 'names') as $name) {
            if (! is_string($name)) {
                continue;
            }

            /** @var array<string,mixed> $detail */
            $detail = $this->reader->read('metrics', [$name]) ?? [];

            $rows = [];
            foreach ($this->subArray($detail, 'measurements') as $measurement) {
                if (! is_array($measurement)) {
                    continue;
                }
                $value = $measurement['value'] ?? null;
                $statistic = $measurement['statistic'] ?? '';
                $rows[] = [
                    'statistic' => is_string($statistic) ? $statistic : '',
                    'value' => is_numeric($value) ? (float) $value : 0.0,
                    'display' => is_numeric($value) ? Format::measurement($name, (float) $value) : '—',
                ];
            }

            $metrics[] = ['name' => $name, 'rows' => $rows];
        }

        return $metrics;
    }

    /**
     * Recent HTTP exchanges, newest first, with each duration pre-formatted.
     *
     * @return list<array<string,mixed>>
     */
    private function exchanges(): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('httpexchanges'), 'exchanges') as $exchange) {
            if (! is_array($exchange)) {
                continue;
            }

            $duration = $exchange['durationMs'] ?? $exchange['duration'] ?? null;
            $rows[] = [
                'method' => is_string($exchange['method'] ?? null) ? $exchange['method'] : '',
                'path' => is_string($exchange['path'] ?? null) ? $exchange['path'] : '',
                'status' => is_numeric($exchange['status'] ?? null) ? (int) $exchange['status'] : 0,
                'duration' => is_numeric($duration) ? Format::milliseconds((float) $duration) : '—',
                'correlationId' => is_string($exchange['correlationId'] ?? null) ? $exchange['correlationId'] : '',
                'timestamp' => is_numeric($exchange['timestamp'] ?? null) ? (float) $exchange['timestamp'] : 0.0,
            ];
        }

        return $rows;
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

    /** @return array<string,mixed> */
    private function payload(string $id): array
    {
        /** @var array<string,mixed> $body */
        $body = $this->reader->read($id) ?? [];

        return $body;
    }

    /** @return array<mixed> */
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
     * Flattens nested firefly.* config into dotted keys — how a developer looks a key up, and how every
     * other part of the framework names one.
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
    private function render(string $view, array $data, ?AdminPage $page = null): string
    {
        return $this->views->make('firefly-admin::'.$view, [
            ...$data,
            'settings' => $this->settings,
            'nav' => $this->nav(),
            'groups' => AdminPage::groups(),
            'active' => $page instanceof AdminPage ? $page->slug : ($view === 'overview' ? '' : $view),
            'page' => $data['page'] ?? $page,
            'now' => microtime(true),
        ])->render();
    }

    private function html(string $body, int $status): SymfonyResponse
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
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
