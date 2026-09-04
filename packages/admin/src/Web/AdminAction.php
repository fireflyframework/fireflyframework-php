<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Admin\AdminEndpointReader;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\BeanGraph;
use Firefly\Admin\Data\DataBrowser;
use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Data\DatasourceReport;
use Firefly\Admin\Format;
use Firefly\Context\Scan\AppScan;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Store;
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
        private ManagementPortGuard $guard,
        private DataBrowser $data,
        private DatasourceReport $datasource,
    ) {}

    public function __invoke(Request $request, string $page = ''): SymfonyResponse
    {
        // The management port boundary applies to the dashboard MORE than to the JSON actuator, not less.
        // The actuator withholds sensitive endpoints behind ExposureModel; this dashboard deliberately
        // bypasses that model so it can render beans, env and config properties in-process. If an operator
        // has moved management traffic to a private port, a dashboard still answering on the public one
        // would publish exactly the surface they moved — and with no signal that it had happened.
        //
        // 404, never 403: a 403 confirms a management surface exists on some other port, which is one more
        // fact than an unauthenticated scan of the public port deserves. Same reasoning, same status, as
        // ManagementPortGuard's own callers in the actuator.
        if (! $this->guard->permits($request)) {
            return $this->html($this->render('missing', ['slug' => trim($page, '/')]), 404);
        }

        $slug = trim($page, '/');
        $current = null;
        foreach (AdminPage::all() as $candidate) {
            if ($candidate->slug === $slug) {
                $current = $candidate;
            }
        }

        // An excluded page is refused, not merely unlisted — see AdminSettings::allows().
        if ($current === null || ! $this->settings->allows($slug)) {
            return $this->html($this->render('missing', ['slug' => $slug]), 404);
        }

        if ($current->requires !== null && ! $this->reader->has($current->requires)) {
            return $this->html($this->render('unavailable', ['page' => $current]), 404);
        }

        if ($slug === 'loggers' && $request->isMethod('POST')) {
            return $this->setLoggerLevel($request);
        }

        if ($slug === 'data') {
            return $request->isMethod('POST') ? $this->dataWrite($request) : $this->dataPage($request);
        }

        if ($slug === 'datasource') {
            return $this->datasourcePage($request, $current);
        }

        return $this->html($this->render($slug === '' ? 'overview' : $slug, $this->data($slug), $current), 200);
    }

    /**
     * The data-layer page.
     *
     * ONE CONNECTION IS PROBED PER PAGE LOAD, not all of them. Opening a socket can hang against a
     * firewalled host, and a page that opened every configured connection would take the slowest one's
     * timeout to render — on the page an operator opens precisely because something is wrong. So the
     * default connection is probed on arrival and any other is probed only when asked for by name, which
     * bounds the work to one connection whatever the config holds.
     */
    private function datasourcePage(Request $request, AdminPage $current): SymfonyResponse
    {
        $connections = $this->datasource->connections();

        $requested = $request->query('probe');
        $probing = is_string($requested) && $requested !== '' ? $requested : $this->datasource->defaultConnection();

        $known = array_column($connections, 'name');
        $probe = in_array($probing, $known, true) ? ['name' => $probing, ...$this->datasource->probe($probing)] : null;

        return $this->html($this->render('datasource', [
            'available' => $this->datasource->available(),
            'default' => $this->datasource->defaultConnection(),
            'connections' => $connections,
            'pooling' => $this->datasource->pooling(),
            'transactional' => $this->datasource->transactionalMethods(),
            'probe' => $probe,
            'probeEnabled' => $this->datasource->probeEnabled(),
        ], $current), 200);
    }

    /**
     * An edit or a delete from the record page.
     *
     * Both go through DataBrowser, which refuses anything the two switches do not permit — this method never
     * decides that itself. The outcome is carried back in the session so a refusal reads as a sentence on
     * the page the operator was already looking at, rather than as a status code they have to interpret.
     */
    private function dataWrite(Request $request): SymfonyResponse
    {
        // A disabled browser answers the same way for every shape. DataBrowser refuses the write regardless
        // — verified: the row is untouched — but redirecting to a page that then 404s tells the caller the
        // request was understood and merely declined, which is a different fact from "this does not exist".
        if (! $this->data->isEnabled()) {
            return $this->html($this->render('data-disabled', []), 404);
        }

        $slug = $request->input('resource');
        $id = $request->input('id');

        if (! is_string($slug) || $slug === '' || ! is_string($id) || $id === '') {
            return $this->html($this->render('data-missing', ['slug' => is_string($slug) ? $slug : '']), 400);
        }

        $back = $this->settings->url('data').'?resource='.urlencode($slug);

        if ($request->input('op') === 'delete') {
            $result = $this->data->delete($slug, $id);

            // A successful delete has nowhere to go back TO, so it lands on the listing.
            return $this->redirect($result->isDone() ? $back : $back.'&id='.urlencode($id), $result->reason);
        }

        /** @var array<string, mixed> $fields */
        $fields = is_array($request->input('f')) ? $request->input('f') : [];

        return $this->redirect($back.'&id='.urlencode($id), $this->data->update($slug, $id, $fields)->reason);
    }

    /**
     * Redirect back with the outcome sentence flashed.
     *
     * Guarded on the session actually being started: the dashboard mounts on the plain router and an
     * application can serve it without session middleware, where with() would throw — and a write that
     * SUCCEEDED failing on its way to reporting success is the worst possible outcome for this control.
     */
    private function redirect(string $to, string $message): RedirectResponse
    {
        $response = new RedirectResponse($to);

        $session = $this->container->bound('session') ? $this->container->get('session') : null;

        if ($session instanceof Store && $session->isStarted()) {
            $response->with('data-message', $message);
        }

        return $response;
    }

    /**
     * The data browser: a resource index, one resource's records, or a single record.
     *
     * All three live behind one slug rather than three routes because the browser's own switch decides
     * whether ANY of it exists, and a disabled browser must answer the same way for every shape rather than
     * 404ing some paths and rendering others.
     */
    private function dataPage(Request $request): SymfonyResponse
    {
        if (! $this->data->isEnabled()) {
            return $this->html($this->render('data-disabled', []), 404);
        }

        $slug = $request->query('resource');
        $slug = is_string($slug) && $slug !== '' ? $slug : null;

        if ($slug === null) {
            return $this->html($this->render('data-index', ['resources' => $this->data->resources()]), 200);
        }

        $id = $request->query('id');
        if (is_string($id) && $id !== '') {
            $record = $this->data->find($slug, $id);

            return $record === null
                ? $this->html($this->render('data-missing', ['slug' => $slug]), 404)
                : $this->html($this->render('data-record', [
                    'record' => $record,
                    'writable' => $this->data->isWritable(),
                    'relations' => $this->data->relationsFor($slug),
                ]), 200);
        }

        $page = (int) ($request->query('page') ?? 1);
        $sort = $request->query('sort');
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $search = $request->query('q');

        // `fk`/`fv` is how a relation link narrows a listing: "the lines whose order_id is 7". DataBrowser
        // drops a column the schema does not have, so a hand-edited pair cannot reach the driver.
        $column = $request->query('fk');
        $value = $request->query('fv');
        $filter = is_string($column) && $column !== '' && is_string($value) && $value !== ''
            ? new DataFilter($column, $value)
            : null;

        return $this->html($this->render('data-list', [
            'listing' => $this->data->list(
                $slug,
                max(1, $page),
                null,
                is_string($sort) && $sort !== '' ? $sort : null,
                $direction,
                is_string($search) && $search !== '' ? $search : null,
                $filter,
            ),
            'writable' => $this->data->isWritable(),
            'relations' => $this->data->relationsFor($slug),
        ]), 200);
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
            // #[ConfigProperties] DTOs are bound and injectable but are neither scanned as components nor
            // produced by a factory, so the beans catalogue alone cannot see them — they arrived as
            // unresolved dependencies instead of as the beans they are.
            'graph' => ['graph' => BeanGraph::build(
                $this->listOf('beans', 'beans'),
                $this->subArray($this->payload('configprops'), 'beans'),
            )],
            'conditions' => $this->payload('conditions') + ['positiveMatches' => [], 'negativeMatches' => []],
            'mappings' => ['mappings' => $this->listOf('mappings', 'mappings')],
            'scheduled' => ['tasks' => $this->listOf('scheduledtasks', 'tasks')],
            'env' => ['env' => $this->flatten($this->subArray($this->payload('env'), 'firefly'), 'firefly')],
            // Shapes verified against the real endpoints: configprops answers {beans: {class => row}}
            // and caches answers {default: name|null, caches: {name => row}}.
            'configprops' => ['beans' => $this->subArray($this->payload('configprops'), 'beans')],
            'caches' => [
                'stores' => $this->subArray($this->payload('caches'), 'caches'),
                'defaultStore' => is_string($this->payload('caches')['default'] ?? null)
                    ? $this->payload('caches')['default']
                    : null,
            ],
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
            fn (AdminPage $page): bool => $this->settings->allows($page->slug)
                // The data browser has no actuator endpoint; its own switch decides whether it is offered.
                && ($page->slug !== 'data' || $this->data->isEnabled())
                // Datasource needs a database manager to describe. An application with none is a legal
                // LaraFly application, and a menu entry leading to "there is nothing here" is worse than no
                // entry at all.
                && ($page->slug !== 'datasource' || $this->datasource->available())
                && ($page->requires === null || $this->reader->has($page->requires)),
        ));
    }
}
