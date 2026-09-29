<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

use DateTimeImmutable;
use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Admin\AdminEndpointReader;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\BeanGraph;
use Firefly\Admin\BeanModules;
use Firefly\Admin\BeanNeighbourhood;
use Firefly\Admin\Data\ConnectionWizard;
use Firefly\Admin\Data\DataBrowser;
use Firefly\Admin\Data\DataColumn;
use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Data\DataListing;
use Firefly\Admin\Data\DataMap;
use Firefly\Admin\Data\DatasourceReport;
use Firefly\Admin\ExplorerQuery;
use Firefly\Admin\Format;
use Firefly\Admin\Route\RouteDetail;
use Firefly\Admin\Route\RouteInspector;
use Firefly\Admin\RowComparator;
use Firefly\Admin\Settings\FeatureToggle;
use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Admin\Table\InMemoryListing;
use Firefly\Admin\Table\ListingPage;
use Firefly\Admin\Table\ListingQuery;
use Firefly\Admin\Table\TableColumn;
use Firefly\Admin\Table\TableView;
use Firefly\Context\Scan\AppScan;
use Firefly\Web\Exception\ExceptionHandlerDescriptor;
use Firefly\Web\Exception\ExceptionHandlerRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

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
        private SettingsConsole $console,
        private ConnectionWizard $wizard,
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

        if ($slug === 'mappings' && $request->query('route') !== null) {
            return $this->mappingPage($request, $current);
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

        if ($slug === 'settings') {
            return $request->isMethod('POST') ? $this->settingsWrite($request) : $this->settingsPage($current);
        }

        if ($slug === 'data-map') {
            // Behind the browser's own switch, not the dashboard's: a schema diagram names every table and
            // column an application has, which is the shape of its data even though it is not the data.
            return $this->data->isEnabled()
                ? $this->html($this->render('data-map', ['map' => DataMap::build($this->data)], $current), 200)
                : $this->html($this->render('data-disabled', []), 404);
        }

        return $this->html($this->render($slug === '' ? 'overview' : $slug, $this->data($request, $slug), $current), 200);
    }

    private function mappingPage(Request $request, AdminPage $current): SymfonyResponse
    {
        $key = $request->query('route');
        $inspector = new RouteInspector($this->container);
        $detail = $this->settings->routeDetail && is_string($key) ? $inspector->detail($key) : null;
        if ($detail === null) {
            return $this->html($this->render('missing', ['slug' => 'route']), 404);
        }
        $manifestFile = AppScan::cachedFile($this->container, AppScan::ROUTES);
        $manifestTime = $manifestFile !== null ? filemtime($manifestFile) : false;
        $query = ListingQuery::fromRequest($request, $this->settings->table, $this->settings->url('mappings'), ['httpMethod', 'path', 'handler', 'name']);
        $handlers = $this->container->bound(ExceptionHandlerRegistry::class)
            ? array_values(array_filter($this->container->make(ExceptionHandlerRegistry::class)->all(),
                static fn (ExceptionHandlerDescriptor $handler): bool => $handler->global || $handler->handlerClass === $detail->route->controllerClass)) : [];

        return $this->html($this->render('mapping', [
            'detail' => $detail, 'query' => $query, 'handlers' => $handlers,
            'bootMode' => $manifestFile !== null ? 'compiled' : 'in-process manifest',
            'manifestTime' => $manifestTime,
            'advice' => $this->settings->routeAdvice ? $inspector->advice($detail->route) : [],
            // File-existence probes only: never require another package's compiled artifact.
            'adviceSource' => AppScan::cachedFile($this->container, AppScan::PROXY_PLAN) !== null ? 'proxy-plan.php'
                : (AppScan::cachedFile($this->container, AppScan::TRANSACTIONAL) !== null ? 'transactional.php only — recompile for the complete advice plan' : 'in-process plan, if registered'),
            'metadata' => $inspector->metadata($detail->route),
            'links' => $this->mappingLinks($detail),
            'bindingView' => TableView::of(TableColumn::number('position', '#', ch: 3), TableColumn::token('name', 'PHP argument', weight: 2),
                TableColumn::pill('kind', 'From', ch: 8), TableColumn::token('key', 'Wire name', weight: 2), TableColumn::qualified('type', 'Type', weight: 3),
                TableColumn::pill('required', 'Required', ch: 8), TableColumn::token('default', 'Compiled default', weight: 2), TableColumn::pill('valid', 'Valid', ch: 5)),
        ], $current), 200);
    }

    /** @return list<array{label: string, url: string}> */
    private function mappingLinks(RouteDetail $detail): array
    {
        $allowed = array_column($this->nav(), 'slug');
        $links = [];
        foreach (['http' => 'HTTP traffic', 'metrics' => 'Metrics'] as $slug => $label) {
            if (in_array($slug, $allowed, true)) {
                $links[] = ['label' => $label, 'url' => $this->settings->url($slug)];
            }
        }
        if (in_array('beans', $allowed, true)) {
            $links[] = ['label' => 'Controller beans', 'url' => $this->settings->url('beans').'?q='.rawurlencode($detail->route->controllerClass)];
        }
        $properties = in_array('configprops', $allowed, true) ? $this->subArray($this->payload('configprops'), 'beans') : [];
        if (in_array('graph', $allowed, true)) {
            $graph = BeanGraph::build($this->listOf('beans', 'beans'), $properties);
            foreach ($graph->nodes as $node) {
                if ($node['id'] === $detail->route->controllerClass) {
                    $links[] = ['label' => 'Controller wiring', 'url' => $this->settings->url('graph').'?bean='.rawurlencode($node['id'])];
                }
            }
            foreach ($graph->edges as $edge) {
                if ($edge['from'] === $detail->route->controllerClass && isset($properties[$edge['to']])) {
                    $links[] = ['label' => 'Configuration: '.Format::leafOf($edge['to']), 'url' => $this->configurationLink($edge['to'], $properties[$edge['to']])];
                }
            }
        }
        foreach ($detail->injected as $binding) {
            $type = $binding->plan['type'];
            if ($binding->resolver === null && $type !== null && isset($properties[$type])) {
                $links[] = ['label' => 'Configuration: '.Format::leafOf($type), 'url' => $this->configurationLink($type, $properties[$type])];
            }
        }
        $router = $this->container->bound('router') ? $this->container->make('router') : null;
        $viewer = $router instanceof Router ? $router->getRoutes()->getByName('firefly.openapi.viewer') : null;
        if ($viewer !== null && $viewer->getDomain() === null && ! str_contains($viewer->uri(), '{')) {
            $links[] = ['label' => 'API reference', 'url' => '/'.ltrim($viewer->uri(), '/')];
        }

        return $links;
    }

    private function configurationLink(string $class, mixed $description): string
    {
        $bound = is_array($description) && ($description['bound'] ?? false) === true;

        return $this->settings->url('configprops').'?'.($bound ? 'props_q' : 'unbound_q').'='.rawurlencode($class);
    }

    private function settingsPage(AdminPage $current): SymfonyResponse
    {
        if (! $this->console->isEnabled()) {
            return $this->html($this->render('settings-disabled', []), 404);
        }

        return $this->html($this->render('settings', [
            'toggles' => $this->console->toggles(),
            // NOT `groups`: render() sets that itself, to the NAV's groups, after spreading this array —
            // so a page variable of the same name is silently replaced and every panel keyed on it vanishes.
            'toggleGroups' => FeatureToggle::groups(),
            'writable' => $this->console->isWritable(),
            'production' => $this->console->isProduction(),
            'overrides' => $this->console->overrides(),
            'file' => $this->console->file(),
        ], $current), 200);
    }

    /**
     * Flip one switch, or clear them all.
     *
     * Nothing is decided here: SettingsConsole refuses a write in production and a key that is not on its
     * fixed list, and this method reports whatever sentence it returns. A 404 for a switched-OFF console is
     * the same refusal the data browser makes for the same reason — a 403 confirms the surface exists.
     */
    private function settingsWrite(Request $request): SymfonyResponse
    {
        if (! $this->console->isEnabled()) {
            return $this->html($this->render('settings-disabled', []), 404);
        }

        $back = $this->settings->url('settings');

        if ($request->input('op') === 'reset') {
            return $this->redirect($back, $this->console->reset());
        }

        $key = $request->input('key');
        if (! is_string($key) || $key === '') {
            return $this->redirect($back, 'Refused: no switch was named.');
        }

        return $this->redirect($back, $this->console->set($key, $request->input('value') === '1'));
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

        // The wizard runs only on a POST — a GET can never open an outbound socket to a caller-supplied
        // host, which keeps the whole surface out of reach of a link, an image tag or a prefetch.
        $trial = $request->isMethod('POST') && $this->wizard->isAvailable()
            ? $this->wizard->test($this->wizardInput($request))
            : null;

        return $this->html($this->render('datasource', [
            'available' => $this->datasource->available(),
            'default' => $this->datasource->defaultConnection(),
            'connections' => $connections,
            'pooling' => $this->datasource->pooling(),
            'transactional' => $this->datasource->transactionalMethods(),
            'dataLayer' => $this->datasource->dataLayer(),
            'probe' => $probe,
            'probeEnabled' => $this->datasource->probeEnabled(),
            'wizard' => $this->wizard,
            'trial' => $trial,
            'trialInput' => $this->wizardInput($request),
        ], $current), 200);
    }

    /**
     * @return array<string, string>
     */
    private function wizardInput(Request $request): array
    {
        $input = [];

        foreach (['driver', 'host', 'port', 'database', 'username', 'password', 'charset'] as $field) {
            $value = $request->input($field);
            $input[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $input;
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
        if (! is_string($slug) || $slug === '') {
            return $this->html($this->render('data-missing', ['slug' => '']), 400);
        }

        $back = $this->settings->url('data').'?resource='.urlencode($slug);

        // A create has no id yet — that is the whole difference — so it is dispatched before the id check
        // the other two operations need.
        if ($request->input('op') === 'create') {
            /** @var array<string, mixed> $new */
            $new = is_array($request->input('f')) ? $request->input('f') : [];
            $result = $this->data->create($slug, $new);

            return $this->redirect(
                $result->isDone() && $result->id !== null ? $back.'&id='.urlencode((string) $result->id) : $back.'&new=1',
                $result->reason,
            );
        }

        $id = $request->input('id');
        if (! is_string($id) || $id === '') {
            return $this->html($this->render('data-missing', ['slug' => $slug]), 400);
        }

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
     * Guarded on the session actually being started: an application can serve the dashboard without
     * session middleware, where with() would throw — and a write that SUCCEEDED failing on its way to
     * reporting success is the worst possible outcome for this control.
     *
     * `session.store`, NOT `session`. The `session` binding is the SessionManager — the factory that hands
     * out stores — and a manager is never a Store, so the guard below was false on every request and no
     * outcome ever reached a page: a refused create looked exactly like a page reload. `session.store` is
     * the Store the StartSession middleware started for this request. And a RedirectResponse built by hand
     * carries no session of its own, so it is handed the store before with() asks it to flash.
     */
    private function redirect(string $to, string $message): RedirectResponse
    {
        $response = new RedirectResponse($to);

        $session = $this->container->bound('session.store') ? $this->container->get('session.store') : null;

        if ($session instanceof Store && $session->isStarted()) {
            $response->setSession($session);
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

        if ($request->query('new') !== null) {
            $resource = $this->data->resource($slug);
            $schema = $this->data->schema($slug);

            return $resource === null || $schema === null
                ? $this->html($this->render('data-missing', ['slug' => $slug]), 404)
                : $this->html($this->render('data-new', [
                    'resource' => $resource,
                    'schema' => $schema,
                    'writable' => $this->data->isWritable(),
                ]), 200);
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

        $filters = $this->filters($request);
        $schema = $this->data->schema($slug);

        // THE BROWSER'S OWN BOUNDS, COMPOSED BEFORE THE REQUEST IS READ. This listing answers to two
        // page-size keys: the dashboard-wide `firefly.admin.table.*` set every table shares, and the
        // browser's own `page-size` / `max-page-size`, which are lower because a row of a customer table is
        // wider than a row of a bean listing, and because a size that is merely large on an actuator payload
        // materialises a whole table into PHP memory on a repository that cannot page. Folding the second
        // pair into the first HERE is what keeps both honest: the query falls back to the browser's own
        // default when `?size=` is absent — rather than stating the table's 50 on every call and leaving
        // `DataBrowserSettings::clampPageSize()` no null to act on — it offers exactly the sizes this
        // listing may serve, and it omits `size` from a link when that size IS the default, so paging
        // cannot silently resize the table. See TableSettings::boundedBy().
        $browser = $this->data->settings();

        $query = ListingQuery::fromRequest(
            $request,
            $this->settings->table->boundedBy($browser->pageSize, $browser->maxPageSize),
            $this->settings->url('data'),
            $schema?->sortable() ?? [],
            defaultSort: $schema?->identifier,
            carried: ['resource' => $slug, ...DataFilter::toParameters($filters)],
        );

        $listing = $this->data->list($slug, $query->page, $query->size, $query->sort, $query->direction, $query->search, $filters);

        // THE PAGER FOLLOWS THE SIZE THE ROWS WERE SERVED AT, whoever decided it. With the bounds composed
        // above the two cannot disagree, and this is the line that says so rather than the line that hopes
        // so: every number a pager draws — the range readout, the last page, whether `Next` is live — is
        // computed from the query, so a query stating a size the rows were not served at would claim the
        // wrong number of pages and disable `Next` with half the table unreached.
        $query = $query->sized($listing->perPage);

        // ONE RE-QUERY, AND ONLY PAST THE END. The in-memory listings clamp `?page=999` onto the last page
        // because they know the total before they slice; SQL does not, so a hand-edited page number comes
        // back as an empty slice with a real total. Rather than show an empty table with a pager under it,
        // the last page is fetched — which costs one extra query in a case that only a hand-edited URL or a
        // stale bookmark reaches, and never on a click, because every link this page emits is in range.
        $last = ListingPage::lastPageFor($listing->total, $query->size);
        if ($listing->rows === [] && $listing->total > 0 && $query->page > $last) {
            $listing = $this->data->list($slug, $last, $query->size, $query->sort, $query->direction, $query->search, $filters);
        }

        return $this->html($this->render('data-list', [
            'listing' => $listing,
            'query' => $query,
            'view' => $this->dataTableView($listing),
            'slice' => ListingPage::sliced($listing->rows, $listing->total, $query),
            'writable' => $this->data->isWritable(),
            'relations' => $this->data->relationsFor($slug),
            'operators' => DataFilter::operators(),
        ]), 200);
    }

    /**
     * A listing of a browsable resource as a TableView — the same object the other twelve listings on this
     * dashboard build, assembled from a resource's SCHEMA instead of from a hand-written column list.
     *
     * WHY THIS EXISTS RATHER THAN A SECOND COLGROUP. `data-list.blade.php` used to write its own width
     * expressions as literal strings: `calc(19ch + 2 * var(--row-x))` for a datetime, thirteen for the
     * numeric kinds, `auto` for the rest. The nineteen was a COPY of `TableColumn::stamp()`'s default,
     * under a comment claiming that the two mechanisms sizing a timestamp on this dashboard agreed — which
     * nothing enforced, no test related, and a change to `stamp()` would have quietly falsified. It is that
     * default now, so there is one number and moving it moves both. What the view keeps is the
     * `t-<dbtype>` class on each cell: those are the DATABASE's types — int, float, bool, datetime, string,
     * json — a fact about the resource DataSchema derived, and ColumnKind models presentation kinds rather
     * than types. So the widths are shared and the header row is not.
     *
     * AND THE RIGID WIDTHS ARE FITTED TO THE HEADERS HERE, which no other listing needs. Every other page's
     * labels are hand-written beside the width chosen to hold them; this one's are humanised from a column
     * name nobody picked for its length, so `failed_login_attempts` draws a 21-character header into a
     * column sized for a five-figure count and `overflow:hidden` takes the rest. See
     * TableColumn::fittingItsHeader().
     */
    private function dataTableView(DataListing $listing): TableView
    {
        $sortable = $listing->schema?->sortable() ?? [];

        $columns = array_map(
            static function (DataColumn $column) use ($sortable): TableColumn {
                $key = $column->name;
                $label = $column->label();
                $orderable = in_array($key, $sortable, true);

                return match ($column->type) {
                    DataColumn::TYPE_DATETIME => TableColumn::stamp($key, $label, sortable: $orderable),
                    // THIRTEEN, WHERE A HAND-WRITTEN `number()` TAKES NINE. Nine characters is a
                    // five-figure count with its thousands separator, which is what a dashboard column an
                    // author chose holds. This one holds whatever its type admits: a bigint key, a
                    // `decimal(12,2)` total that arrives as the string `1234567.89`, or the word `false`.
                    DataColumn::TYPE_INT, DataColumn::TYPE_FLOAT, DataColumn::TYPE_BOOL => TableColumn::number($key, $label, ch: 13, sortable: $orderable),
                    // Text and json take a SHARE of what the rigid columns leave, which is where a reader
                    // of a data browser needs the room — and an equal share only because a schema gives no
                    // ground to prefer one text column over another.
                    default => TableColumn::text($key, $label, sortable: $orderable),
                };
            },
            $listing->columns(),
        );

        $columns = array_map(
            static fn (TableColumn $column): TableColumn => $column->fittingItsHeader(),
            $columns,
        );

        if ($listing->schema?->identifierColumn() !== null) {
            // NINE, WHERE `actions()` DEFAULTS TO ELEVEN: eleven is a column holding a form with a button,
            // and this one holds a single `Open →` link. Its header is empty, so nothing fits it to.
            $columns[] = TableColumn::actions(ch: 9);
        }

        return TableView::of(...$columns);
    }

    /**
     * The filters a listing URL carries, in either spelling.
     *
     * TWO SPELLINGS, ONE MEANING. `fk`/`fv` is a single equality and is what every relation link produces —
     * short enough to read in a status bar. `fc[]`/`fo[]`/`fv[]` is what the filter bar builds, and carries a
     * column, an operator and a value per condition. Both are validated identically downstream: DataBrowser
     * drops any column the schema does not publish and any operator outside the fixed set, so neither
     * spelling is a wider surface than the other.
     *
     * @return list<DataFilter>
     */
    private function filters(Request $request): array
    {
        $columns = $request->query('fc');
        $operators = $request->query('fo');
        $values = $request->query('fv');

        if (is_array($columns)) {
            $operators = is_array($operators) ? $operators : [];
            $values = is_array($values) ? $values : [];

            $filters = [];
            foreach (array_values($columns) as $index => $column) {
                if (! is_string($column) || $column === '') {
                    continue;
                }

                $operator = $operators[$index] ?? DataFilter::EQ;
                $value = $values[$index] ?? '';

                $filters[] = new DataFilter(
                    $column,
                    is_string($operator) ? $operator : DataFilter::EQ,
                    is_string($value) ? $value : '',
                );
            }

            return $filters;
        }

        $short = $request->query('fk');

        return is_string($short) && $short !== '' && is_string($values) && $values !== ''
            ? [new DataFilter($short, DataFilter::EQ, $values)]
            : [];
    }

    /**
     * The model for one page.
     *
     * IT TAKES THE REQUEST, AND UNTIL THIS WAVE IT DID NOT. `__invoke` had one and this method did not, so
     * no page in the dashboard could read a query parameter — which is why every listing rendered its whole
     * payload into the response and narrowed it with a keyup handler. It is private with one caller.
     *
     * @return array<string,mixed>
     */
    private function data(Request $request, string $slug): array
    {
        return match ($slug) {
            '' => $this->overview(),
            'health' => ['indicators' => $this->reader->healthIndicators(), 'aggregate' => $this->aggregateStatus()],
            'metrics' => $this->metricsPage($request),
            // A LOG IS THE ONE LISTING ON THIS DASHBOARD THAT OPENS ON AN ORDER NOBODY ASKED FOR. Its
            // tiebreak distinguishes exchanges even when a caller reuses a correlation id, and newest-first is a deliberate choice about the page rather
            // than the identity the rows happen to have, so it is declared — and `meaningful()` then keeps
            // the pair out of every URL the page writes, which is how /firefly/http stays /firefly/http.
            // See listing()'s docblock for why the other listings declare nothing.
            'http' => $this->listing(
                $request,
                'http',
                $this->exchanges(),
                TableView::of(
                    // SIXTEEN, BECAUSE THAT IS THE ALPHABET Format::since CAN EMIT — not the six of
                    // `2h ago`. Past its 86400-second arm the formatter stops giving an age and gives
                    // `2026-09-22 20:49`, and this ring is cache-backed on purpose (the page's own empty
                    // state says so), so a low-traffic or freshly-idle application routinely lists
                    // requests older than a day. Measured against the sheet: at `ch: 12` the column is a
                    // 118px box whose text starts after the 14px left padding and runs 115px, so the last
                    // glyph and a half are cut off by `table.ftable td{overflow:hidden}`. 14 is the first
                    // width that stops clipping and 16 the first that fits without spilling into the right
                    // padding, at both densities.
                    TableColumn::stamp('timestamp', 'When', ch: 16),
                    TableColumn::pill('method', 'Method'),
                    TableColumn::path('path', 'Path', weight: 6),
                    TableColumn::pill('status', 'Status', ch: 6),
                    // `duration` IS PRE-FORMATTED — `12.4 ms`, `1.2 s` — so ordering by it would order by
                    // the leading digit and put `9.1 ms` after `1.2 s`. Same reason the scheduled tasks'
                    // interval columns are unsortable; the fix is a numeric column, not a sort link.
                    TableColumn::number('duration', 'Took', ch: 10, sortable: false),
                    TableColumn::token('correlationId', 'Correlation', weight: 2),
                    TableColumn::token('traceId', 'Trace', weight: 2),
                ),
                ['path', 'method', 'status', 'correlationId', 'traceId'],
                'row',
                defaultSort: 'timestamp',
                defaultDirection: 'desc',
            ),
            'beans' => $this->beansPage($request),
            'graph' => $this->graphPage($request),
            'conditions' => $this->conditionsPage($request),
            'mappings' => $this->listing(
                $request,
                'mappings',
                $this->mappingRows(),
                TableView::of(
                    TableColumn::pill('httpMethod', 'Method'),
                    TableColumn::path('path', 'Path', weight: 5),
                    TableColumn::qualified('handler', 'Handler', weight: 4),
                    TableColumn::token('name', 'Name', weight: 3),
                ),
                ['path', 'handler', 'name'],
                'row',
            ),
            'scheduled' => $this->listing(
                $request,
                'scheduled',
                $this->taskRows(),
                TableView::of(
                    TableColumn::qualified('runnable', 'Runnable', weight: 5),
                    TableColumn::token('cron', 'Cron', weight: 3),
                    TableColumn::number('fixedRate', 'Fixed rate', ch: 11, sortable: false),
                    TableColumn::number('fixedDelay', 'Fixed delay', ch: 11, sortable: false),
                    TableColumn::token('zone', 'Zone', weight: 2),
                ),
                ['runnable', 'cron', 'zone'],
                'runnable',
            ),
            'oauth2' => $this->oauth2Page($request),
            // Shapes verified against the real endpoints: configprops answers {beans: {class => row}}
            // and caches answers {default: name|null, caches: {name => row}}.
            'env' => $this->listing(
                $request,
                'env',
                $this->envRows(),
                TableView::of(
                    TableColumn::qualified('key', 'Key', weight: 4, separator: '.'),
                    TableColumn::line('value', 'Value', weight: 5),
                ),
                ['key', 'value'],
                'key',
            ),
            'configprops' => $this->configPropsPage($request),
            'caches' => [
                ...$this->listing(
                    $request,
                    'caches',
                    $this->cacheRows(),
                    TableView::of(
                        TableColumn::token('name', 'Store', weight: 3),
                        TableColumn::token('driver', 'Driver', weight: 3),
                        // 10.5 CHARACTERS FOR A SEVEN-CHARACTER WORD, because a `.chip` is not only its
                        // text: a 6px dot, the 6px gap after it and 2x9px of its own padding are 30px of
                        // chrome that the column's `calc(<n>ch + 2 * var(--row-x))` does not cover —
                        // `--row-x` is the CELL's padding, not the chip's. Measured in Chromium: the chip
                        // is 71.8px and 8ch gave it 60px, so `overflow:hidden` cut the last two letters
                        // off the word `default` on every application, since `cache.default` is always
                        // set and the row therefore always renders.
                        TableColumn::pill('default', 'Default', ch: 10.5),
                    ),
                    ['name', 'driver'],
                    'name',
                ),
                'defaultStore' => is_string($this->payload('caches')['default'] ?? null)
                    ? $this->payload('caches')['default']
                    : null,
            ],
            'loggers' => [
                ...$this->listing(
                    $request,
                    'loggers',
                    $this->loggerRows(),
                    TableView::of(
                        TableColumn::token('name', 'Channel', weight: 4),
                        // The pill's padding again, and here the widest value is not a guess: the level is
                        // whatever `logging.channels.*.level` says, upper-cased, so CRITICAL and EMERGENCY
                        // are both reachable — and a testbench reports CRITICAL today. `.code` is 11.5px
                        // mono inside 2x7px, which makes EMERGENCY 76.3px against the 67.6px that 9ch gave
                        // it. 11 characters is 82.7px.
                        TableColumn::pill('level', 'Level', ch: 11),
                        // THE COLUMN IS SIZED FROM THE CONTROL, NOT FROM THE WORD `Apply`. This cell holds
                        // a <select> and a button, and a <select> is as wide as its widest OPTION — the
                        // endpoint publishes Monolog's whole `Level::NAMES`, so EMERGENCY is in it on every
                        // install and the control measures 115px + the 6px flex gap + a 51.5px button =
                        // 172.5px. At the 18ch this shipped with, the content box was 135px and
                        // `table.ftable td{overflow:hidden}` cut 23px off the button: it rendered as `App`
                        // and the rest of it was not hit-testable. 24 characters is 180.4px.
                        TableColumn::actions('Set', ch: 24),
                    ),
                    ['name', 'level'],
                    'name',
                ),
                'levels' => $this->subArray($this->payload('loggers'), 'levels'),
            ],
            default => [],
        };
    }

    /**
     * One page of an actuator payload, plus the query that produced it.
     *
     * The view is handed the TableView as well, because the columns decide THREE things at once and they
     * must not be able to disagree: the widths in the <colgroup>, the headers, and which keys `?sort=` will
     * accept. Passing `$view->sortable()` into the query is what stops a hand-edited `?sort=password`
     * ordering by something the page does not draw.
     *
     * `$defaultSort` IS LEFT NULL FOR A LISTING WHOSE NATURAL ORDER IS ITS TIEBREAK, and every listing on
     * this dashboard but one is such a listing. InMemoryListing falls back to the tiebreak column when no
     * sort was asked for, so declaring the same key as the default sort changes nothing whatever about the
     * rows and two things about the page: `meaningful()` omits a parameter that is already at its default,
     * so THAT column's header link loses its own `?sort=` and degrades to a bare `?dir=desc` — the other
     * columns keep theirs — and `indicator()` draws an arrow on it from the first request, before the
     * reader has ordered anything.
     *
     * Neither reading is wrong on its own; having BOTH of them in one dashboard is, and that is what the
     * Configuration listings briefly shipped. `/firefly/env` opened with an arrow on Key while
     * `/firefly/mappings` opened with none on Path, though each was ordered by exactly that column — one
     * affordance answering "what is this sorted by?" two ways depending on which page a reader was on.
     * The convention this file keeps is the one `/firefly/mappings` already had: an arrow means A READER
     * ASKED FOR THIS ORDERING, and a listing nobody has ordered yet names every column in its header links
     * and marks none of them.
     *
     * SO A DEFAULT SORT IS DECLARED ONLY WHERE THE OPENING ORDER IS NOT THE TIEBREAK — where the arrow is
     * therefore saying something the reader could not have worked out. The bound config-properties panel
     * is this file's one case today: its tiebreak is `row`, a key the page does not draw, so a null
     * default would order the table by a column no header can name and no reader can click back to, and
     * `class` is the drawn column that ordering amounts to. See configPropsPage(). A listing whose opening
     * order is a deliberate choice rather than an identity — a log newest-first — declares one for the
     * same reason.
     *
     * GENERIC OVER THE ROW, the way ListingPage is: a caller that hands in a precisely-shaped
     * `list<array{...}>` gets that shape back in `$slice->rows`, so the view draws `$route['path']` without
     * re-asserting that it is a string and PHPStan at level max has nothing to complain about.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  list<TRow>  $rows
     * @param  list<string>  $searchable
     * @return array{query: ListingQuery, slice: ListingPage<TRow>, view: TableView}
     */
    private function listing(
        Request $request,
        string $slug,
        array $rows,
        TableView $view,
        array $searchable,
        string $tiebreak,
        ?string $defaultSort = null,
        string $defaultDirection = 'asc',
        string $qualifier = '',
    ): array {
        $query = ListingQuery::fromRequest(
            $request,
            $this->settings->table,
            $this->settings->url($slug),
            $view->sortable(),
            defaultSort: $defaultSort,
            defaultDirection: $defaultDirection,
            qualifier: $qualifier,
        );

        return [
            'query' => $query,
            'slice' => InMemoryListing::page($rows, $query, $searchable, $tiebreak),
            'view' => $view,
        ];
    }

    private function beanGraph(): BeanGraph
    {
        return BeanGraph::build($this->listOf('beans', 'beans'), $this->subArray($this->payload('configprops'), 'beans'));
    }

    /** @return array<string,mixed> */
    private function beansPage(Request $request): array
    {
        $graph = $this->beanGraph();
        $rows = [];
        $catalogue = array_column($this->beanRows(), null, 'class');
        foreach ($graph->nodes as $node) {
            $rows[] = [...($catalogue[$node['id']] ?? ['name' => '', 'interfaces' => '', 'interfacesQualified' => '']), ...$node, 'class' => $node['id'], 'module' => BeanGraph::moduleOf($node['id'])];
        }
        $view = TableView::of(
            TableColumn::qualified('class', 'Class', weight: 5),
            TableColumn::token('kind', 'Kind', weight: 2),
            TableColumn::token('stereotype', 'Stereotype', weight: 2),
            TableColumn::token('scope', 'Scope', weight: 2),
            TableColumn::text('module', 'Module', weight: 3, sortable: true),
            TableColumn::text('interfaces', 'Implements', weight: 3, sortable: true),
            TableColumn::number('in', 'Dependents', ch: 11),
            TableColumn::number('out', 'Dependencies', ch: 13),
        );
        $query = ListingQuery::fromRequest($request, $this->settings->table->boundedBy($this->settings->graph->beansPageSize, 500), $this->settings->url('beans'), $view->sortable(), 'in', in_array($request->query('sort'), $view->sortable(), true) && $request->query('sort') !== 'in' ? 'asc' : 'desc');

        return ['graph' => $graph, 'view' => $view, 'query' => $query, 'slice' => InMemoryListing::page($rows, $query, ['class', 'kind', 'stereotype', 'scope', 'module', 'detail', 'name', 'interfacesQualified'], 'class')];
    }

    /** @return array<string,mixed> */
    private function graphPage(Request $request): array
    {
        $graph = $this->beanGraph();
        $explorer = ExplorerQuery::fromRequest($request, $this->settings->url('graph'), $this->settings->graph);
        $nodes = array_column($graph->nodes, null, 'id');
        $picked = $nodes[$explorer->get('bean')] ?? null;
        $modules = BeanModules::fromGraph($graph, $explorer->get('produces') === '1');
        $focus = $picked === null ? null : BeanNeighbourhood::around($graph, $picked['id'], $explorer->depth, $explorer->direction, $this->settings->graph);
        $conditions = [];
        $report = $this->payload('conditions');
        $classes = $picked === null ? [] : [$picked['id']];
        foreach ($graph->edges as $edge) {
            if ($picked !== null && $edge['to'] === $picked['id'] && $edge['type'] === BeanGraph::EDGE_PRODUCES) {
                $classes[] = $edge['from'];
            }
        }
        foreach (['positiveMatches' => 'Applied', 'negativeMatches' => 'Backed off'] as $key => $outcome) {
            foreach ($this->subArray($report, $key) as $row) {
                if (is_array($row) && is_string($row['class'] ?? null) && in_array($row['class'], $classes, true) && is_string($row['condition'] ?? null)) {
                    $conditions[] = ['class' => $row['class'], 'condition' => $row['condition'], 'outcome' => $outcome];
                }
            }
        }
        $starters = $graph->nodes;
        usort($starters, static fn (array $a, array $b): int => [$b['in'], $a['id']] <=> [$a['in'], $b['id']]);
        $roots = array_values(array_filter($graph->nodes, static fn (array $node): bool => $node['in'] === 0));
        usort($roots, static fn (array $a, array $b): int => [$b['out'], $a['id']] <=> [$a['out'], $b['id']]);
        $rows = array_values(array_filter($graph->nodes, static fn (array $node): bool => $explorer->get('module') === '' || BeanGraph::moduleOf($node['id']) === $explorer->get('module')));
        $view = TableView::of(TableColumn::qualified('id', 'Bean', weight: 5), TableColumn::token('kind', 'Kind', weight: 2), TableColumn::number('in', 'Dependents', ch: 11), TableColumn::number('out', 'Dependencies', ch: 13));
        $query = ListingQuery::fromRequest($request, $this->settings->table->boundedBy($this->settings->graph->pageSize, 500), $this->settings->url('graph'), $view->sortable(), 'in', 'desc', $explorer->carried('beans'), 'beans');
        // Search is the landing form's unqualified q; module paging uses a qualified listing.
        $searchRequest = Request::create('/', 'GET', [...$request->query(), 'beans_q' => $explorer->get('q')]);
        $query = ListingQuery::fromRequest($searchRequest, $query->settings, $query->path, $view->sortable(), 'in', 'desc', $explorer->carried('beans'), 'beans');
        $relations = [];
        $source = $explorer->get('neighbor') ?: ($picked['id'] ?? '');
        foreach (['in' => 'Depended on by', 'out' => 'Depends on'] as $side => $title) {
            $relationRows = [];
            foreach ($graph->edges as $index => $edge) {
                if ($edge[$side === 'in' ? 'to' : 'from'] !== $source) {
                    continue;
                }
                $id = $edge[$side === 'in' ? 'from' : 'to'];
                $relationRows[] = ['id' => $id, 'via' => $edge['via'] ?? '', 'type' => $edge['type'], 'row' => $edge['from']."\0".$edge['to']."\0".$edge['type']."\0".($edge['via'] ?? '')];
            }
            $relationView = TableView::of(TableColumn::qualified('id', 'Bean', weight: 5), TableColumn::qualified('via', 'Via interface', weight: 4), TableColumn::token('type', 'Relation', weight: 2));
            $relationQuery = ListingQuery::fromRequest($request, $query->settings, $query->path, $relationView->sortable(), carried: $explorer->carried($side), qualifier: $side);
            $relations[$side] = ['title' => $title, 'view' => $relationView, 'query' => $relationQuery, 'slice' => InMemoryListing::page($relationRows, $relationQuery, ['id', 'via', 'type'], 'row')];
        }

        $cycleRows = [];
        $cycles = $graph->components();
        foreach ($cycles as $component => $members) {
            if ($explorer->get('cycle') !== '' && $explorer->get('cycle') !== (string) $component) {
                continue;
            }
            foreach ($members as $id) {
                $cycleRows[] = ['component' => $component, 'id' => $id];
            }
        }
        $cycleView = TableView::of(TableColumn::number('component', 'Component', ch: 11), TableColumn::qualified('id', 'Member', weight: 5));
        $cycleQuery = ListingQuery::fromRequest($request, $query->settings, $query->path, $cycleView->sortable(), carried: $explorer->carried('cycles'), qualifier: 'cycles');
        $unresolvedRows = array_map(static fn (string $id): array => ['id' => $id], $graph->unresolved);
        $unresolvedView = TableView::of(TableColumn::qualified('id', 'Unresolved type', weight: 5));
        $unresolvedQuery = ListingQuery::fromRequest($request, $query->settings, $query->path, $unresolvedView->sortable(), carried: $explorer->carried('unresolved'), qualifier: 'unresolved');
        $pathRows = [];
        foreach ($focus->paths ?? [] as $chain => $path) {
            foreach ($path as $hop => $id) {
                $pathRows[] = ['chain' => $chain + 1, 'hop' => $hop, 'id' => $id, 'row' => sprintf('%02d:%08d', $chain, $hop)];
            }
        }
        $pathView = TableView::of(TableColumn::number('chain', 'Chain', ch: 6), TableColumn::number('hop', 'Hop', ch: 5), TableColumn::qualified('id', 'Bean', weight: 5));
        $pathQuery = ListingQuery::fromRequest($request, $query->settings, $query->path, $pathView->sortable(), carried: $explorer->carried('paths'), qualifier: 'paths');
        $moduleGraph = BeanModules::scope($graph, $explorer->get('module'));
        $moduleAnchor = $rows;
        usort($moduleAnchor, static fn (array $a, array $b): int => [$b['in'] + $b['out'], $a['id']] <=> [$a['in'] + $a['out'], $b['id']]);
        $modulePicked = $moduleAnchor[0] ?? null;
        $moduleFocus = $modulePicked === null ? null : BeanNeighbourhood::around($moduleGraph, $modulePicked['id'], $explorer->depth, 'both', $this->settings->graph);
        $couplingView = TableView::of(TableColumn::qualified('from', 'From', weight: 4), TableColumn::qualified('to', 'To', weight: 4), TableColumn::number('weight', 'Weight', ch: 7), TableColumn::number('beans', 'Beans', ch: 6), TableColumn::number('via', 'Via', ch: 5), TableColumn::number('concrete', 'Concrete', ch: 9));
        $couplingQuery = ListingQuery::fromRequest($request, $query->settings, $query->path, $couplingView->sortable(), carried: $explorer->carried('coupling'), qualifier: 'coupling');
        $couplingRows = [];
        foreach ($modules->edges as $edge) {
            if ($explorer->get('module') === '' || $edge['from'] === $explorer->get('module') || $edge['to'] === $explorer->get('module')) {
                $couplingRows[] = [...$edge, 'row' => $edge['from']."\0".$edge['to']];
            }
        }
        $moduleView = TableView::of(TableColumn::qualified('id', 'Module', weight: 5), TableColumn::number('count', 'Beans', ch: 8), TableColumn::token('cycle', 'Cycle', weight: 2));
        $moduleQuery = ListingQuery::fromRequest($request, $query->settings, $query->path, $moduleView->sortable(), carried: $explorer->carried('modules'), qualifier: 'modules');
        $moduleCycles = [];
        foreach ($modules->cycles as $index => $members) {
            foreach ($members as $id) {
                $moduleCycles[$id] = 'Cycle '.($index + 1);
            }
        }
        $moduleRows = [];
        foreach ($modules->nodes as $id => $info) {
            $moduleRows[] = ['id' => $id, 'cycle' => $moduleCycles[$id] ?? '', ...$info];
        }

        return [
            'cycleView' => $cycleView, 'cycleQuery' => $cycleQuery, 'cycleSlice' => InMemoryListing::page($cycleRows, $cycleQuery, ['id'], 'id'),
            'unresolvedView' => $unresolvedView, 'unresolvedQuery' => $unresolvedQuery, 'unresolvedSlice' => InMemoryListing::page($unresolvedRows, $unresolvedQuery, ['id'], 'id'),
            'pathView' => $pathView, 'pathQuery' => $pathQuery, 'pathSlice' => InMemoryListing::page($pathRows, $pathQuery, ['id'], 'row'),
            'moduleGraph' => $moduleGraph, 'modulePicked' => $modulePicked, 'moduleFocus' => $moduleFocus,
            'couplingView' => $couplingView, 'couplingQuery' => $couplingQuery, 'couplingSlice' => InMemoryListing::page($couplingRows, $couplingQuery, ['from', 'to'], 'row'),
            'moduleView' => $moduleView, 'moduleQuery' => $moduleQuery, 'moduleSlice' => InMemoryListing::page($moduleRows, $moduleQuery, ['id'], 'id'),
            'graph' => $graph, 'explorer' => $explorer, 'picked' => $picked, 'focus' => $focus, 'nodes' => $nodes, 'modules' => $modules,
            'conditions' => $conditions, 'cycles' => $cycles, 'relations' => $relations, 'relationSource' => $source,
            'starters' => array_slice($starters, 0, $this->settings->graph->starters), 'roots' => array_slice($roots, 0, $this->settings->graph->starters),
            'view' => $view, 'query' => $query, 'slice' => InMemoryListing::page($rows, $query, ['id', 'detail', 'stereotype'], 'id'),
            'exclusive' => $explorer->get('module') === '' ? [] : BeanModules::exclusive($graph, $explorer->get('module')),
        ];
    }

    /**
     * The two listings the Conditions page shows side by side.
     *
     * Each takes a QUALIFIER, so its parameters are `pos_page`/`neg_page` rather than one shared `page` —
     * the same shape Spring gives a controller resolving two Pageables with `@Qualifier`. Each then
     * CARRIES the other's parameters, which is the half that is easy to forget: without it, paging the
     * Applied panel rebuilds a URL with no `neg_page` in it and the Backed-off panel silently jumps back
     * to its first page while the reader was looking somewhere else.
     *
     * THE SLICES ARE REBUILT ON THE CARRYING QUERIES, and that is not ceremony. A ListingPage holds the
     * query it was BUILT with and `_pager` draws every page link through `$slice->link()`, so a page
     * rebuilt from the plain query would carry the panel's own position and drop its sibling's — exactly
     * the bug the carrying is there to prevent, one mechanism further down. Rebuilding is free: the rows
     * and the total are already computed, and `sliced()` re-derives the same effective page from them.
     *
     * @return array<string,mixed>
     */
    private function conditionsPage(Request $request): array
    {
        $view = TableView::of(
            TableColumn::qualified('class', 'Class', weight: 5),
            TableColumn::token('condition', 'Condition', weight: 3),
        );

        $applied = $this->listing($request, 'conditions', $this->conditionRows('positiveMatches'), $view, ['class', 'condition'], 'row', qualifier: 'pos');
        $backed = $this->listing($request, 'conditions', $this->conditionRows('negativeMatches'), $view, ['class', 'condition'], 'row', qualifier: 'neg');

        $appliedQuery = $applied['query']->carrying($backed['query']->own());
        $backedQuery = $backed['query']->carrying($applied['query']->own());

        return [
            'appliedQuery' => $appliedQuery,
            'applied' => ListingPage::sliced($applied['slice']->rows, $applied['slice']->total, $appliedQuery),
            'backedQuery' => $backedQuery,
            'backed' => ListingPage::sliced($backed['slice']->rows, $backed['slice']->total, $backedQuery),
            'view' => $view,
        ];
    }

    /**
     * The two listings the Config properties page shows: what bound, and what did not.
     *
     * They are qualified `props` and `unbound` and carry each other, for the same reason the two conditions
     * panels do. The unbound panel is usually empty and is paged anyway: an application whose profiles are
     * misconfigured can have dozens, and "usually small" is not a size.
     *
     * @return array<string,mixed>
     */
    private function configPropsPage(Request $request): array
    {
        $boundView = TableView::of(
            TableColumn::qualified('class', 'Class', weight: 4),
            TableColumn::token('prefix', 'Prefix', weight: 3),
            TableColumn::token('key', 'Property', weight: 3),
            TableColumn::line('value', 'Value', weight: 4),
        );
        $unboundView = TableView::of(
            TableColumn::qualified('class', 'Class', weight: 4),
            TableColumn::token('prefix', 'Prefix', weight: 2),
            TableColumn::text('why', 'Why', weight: 5),
        );

        // `row` IS THE TIEBREAK, AND `key` CANNOT BE. InMemoryListing asks for "a key whose value is unique
        // per row", and this listing emits one row per PROPERTY across every bound DTO — so `enabled`,
        // `timeout` and `store` name as many rows as there are DTOs declaring them, and ordering by Property
        // would leave the primary column and the tiebreak as the same non-unique key, i.e. ties broken by
        // nothing. `row` is the identity the other listings already have as a natural column (a path, a
        // class, a channel name) and this one does not; it is searched by nothing and drawn by nothing, in
        // the same way `interfacesQualified` rides along in the beans catalogue.
        //
        // WHICH IS WHY ONLY THIS PANEL DECLARES A DEFAULT SORT, and the one under it does not. A tiebreak
        // the page does not draw is an ordering no header can claim, so the bound panel names `class` —
        // the drawn column that ordering already amounts to — and opens with the arrow on it. The unbound
        // panel's tiebreak IS its Class column, so it opens the way every other listing on this dashboard
        // opens: ordered by class, arrow on nothing, every header link naming its own column. See the
        // convention in listing()'s docblock.
        $bound = $this->listing($request, 'configprops', $this->configPropRows(), $boundView, ['class', 'prefix', 'key', 'value'], 'row', defaultSort: 'class', qualifier: 'props');
        $unbound = $this->listing($request, 'configprops', $this->unboundRows(), $unboundView, ['class', 'prefix', 'why'], 'class', qualifier: 'unbound');

        $boundQuery = $bound['query']->carrying($unbound['query']->own());
        $unboundQuery = $unbound['query']->carrying($bound['query']->own());

        return [
            'boundQuery' => $boundQuery,
            'bound' => ListingPage::sliced($bound['slice']->rows, $bound['slice']->total, $boundQuery),
            'boundView' => $boundView,
            'unboundQuery' => $unboundQuery,
            'unbound' => ListingPage::sliced($unbound['slice']->rows, $unbound['slice']->total, $unboundQuery),
            'unboundView' => $unboundView,
        ];
    }

    /**
     * The route table as rows the listing engine can sort and search.
     *
     * Normalised here rather than in the view for two reasons: an actuator payload is `array<mixed>` and
     * PHPStan at level max is right to insist somebody say otherwise, and a sort over `$route['path']` has
     * to compare strings rather than "whatever the endpoint put there" — one int in that column and
     * strnatcasecmp is comparing a number to a name.
     *
     * @return list<array{httpMethod: string, path: string, handler: string, name: string, row: string, shadowed: bool}>
     */
    private function mappingRows(): array
    {
        $rows = [];
        foreach ($this->listOf('mappings', 'mappings') as $route) {
            if (! is_array($route)) {
                continue;
            }

            $row = [
                'httpMethod' => is_string($route['httpMethod'] ?? null) ? $route['httpMethod'] : '',
                'path' => is_string($route['path'] ?? null) ? $route['path'] : '',
                'handler' => is_string($route['handler'] ?? null) ? $route['handler'] : '',
                'name' => is_string($route['name'] ?? null) ? $route['name'] : '',
            ];
            $row['row'] = $row['path']."\0".$row['httpMethod']."\0".$row['handler']."\0".$row['name'];
            $rows[] = $row;
        }

        $last = [];
        foreach ($rows as $index => $row) {
            $last[$row['httpMethod'].' '.$row['path']] = $index;
        }
        foreach ($rows as $index => &$row) {
            $row['shadowed'] = $last[$row['httpMethod'].' '.$row['path']] !== $index;
        }
        unset($row);

        return $rows;
    }

    /**
     * The bean catalogue as rows. A bean's interfaces are a LIST, and a list is neither searchable nor
     * orderable by the rules the rest of the table system applies, so each is flattened to a space-joined
     * string — twice, under two keys, because the two jobs want two different strings.
     *
     * `interfaces` HOLDS THE LEAF NAMES AND IS WHAT THE COLUMN DRAWS. Ordering a column by a value the
     * reader cannot see is the same defect as sorting Fixed rate by the leading digit of a duration: a
     * column of `HealthIndicator`, `ActuatorEndpoint`, `Comparable` ordered by its FQCNs comes back grouped
     * by namespace under a header that promised alphabetical, and nothing about the page says why.
     *
     * `interfacesQualified` HOLDS THE FQCNs AND IS WHAT `?q=` ALSO LOOKS INSIDE. The fully qualified name is
     * the one an operator has to hand, pasted out of the editor they came from, and pasting it used to empty
     * this page while three beans implemented exactly it — the one search on this dashboard that answered
     * "nothing matches" to a term that matches. Both halves of the page promise otherwise in words: the
     * placeholder says "class, stereotype or interface" and the empty state names interfaces outright.
     *
     * SEARCHING A VALUE THE READER CANNOT SEE is InMemoryListing's one prohibition, and this does not break
     * it: the qualified list is drawn on the cell's `title`, the same way the Class column keeps the FQCN
     * in the row, shows the leaf and puts the whole name on hover. The truncation had emptied that title of
     * its purpose too — a cell reading `ActuatorEndpoint` carrying `title="ActuatorEndpoint"`.
     *
     * @return list<array{class: string, stereotype: string, scope: string, name: string, interfaces: string, interfacesQualified: string}>
     */
    private function beanRows(): array
    {
        $rows = [];
        foreach ($this->listOf('beans', 'beans') as $bean) {
            if (! is_array($bean)) {
                continue;
            }

            $leaves = [];
            $qualified = [];
            foreach (is_array($bean['interfaces'] ?? null) ? $bean['interfaces'] : [] as $interface) {
                $name = is_string($interface) ? $interface : '';
                $leaves[] = Format::leafOf($name);
                $qualified[] = $name;
            }

            $rows[] = [
                'class' => is_string($bean['class'] ?? null) ? $bean['class'] : '',
                'stereotype' => is_string($bean['stereotype'] ?? null) ? $bean['stereotype'] : '',
                'scope' => is_string($bean['scope'] ?? null) ? $bean['scope'] : '',
                'name' => is_string($bean['name'] ?? null) ? $bean['name'] : '',
                'interfaces' => implode(' ', $leaves),
                'interfacesQualified' => implode(' ', $qualified),
            ];
        }

        return $rows;
    }

    /**
     * One side of the condition report as rows.
     *
     * @param  string  $key  `positiveMatches` or `negativeMatches`
     * @return list<array{class: string, condition: string, row: string}>
     */
    private function conditionRows(string $key): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('conditions'), $key) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row = [
                'class' => is_string($row['class'] ?? null) ? $row['class'] : '',
                'condition' => is_string($row['condition'] ?? null) ? $row['condition'] : '',
            ];
            $row['row'] = $row['class']."\0".$row['condition'];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The scheduled manifest as rows.
     *
     * @return list<array{runnable: string, cron: string, fixedRate: string, fixedDelay: string, zone: string}>
     */
    private function taskRows(): array
    {
        $rows = [];
        foreach ($this->listOf('scheduledtasks', 'tasks') as $task) {
            if (! is_array($task)) {
                continue;
            }

            $rows[] = [
                'runnable' => is_string($task['runnable'] ?? null) ? $task['runnable'] : '',
                'cron' => $this->trigger($task['cron'] ?? null),
                'fixedRate' => $this->trigger($task['fixedRate'] ?? null),
                'fixedDelay' => $this->trigger($task['fixedDelay'] ?? null),
                'zone' => is_string($task['zone'] ?? null) ? $task['zone'] : '',
            ];
        }

        return $rows;
    }

    /**
     * The resolved `firefly.*` configuration as rows. `flatten()` already returns dotted keys sorted; the
     * listing then owns the ordering, so this only reshapes.
     *
     * @return list<array{key: string, value: string}>
     */
    private function envRows(): array
    {
        $rows = [];
        foreach ($this->flatten($this->subArray($this->payload('env'), 'firefly'), 'firefly') as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value];
        }

        return $rows;
    }

    /**
     * One row per bound PROPERTY rather than per DTO. A reader looks a value up by its key, and a table of
     * one row per class with a blob of properties in a cell cannot be searched that way.
     *
     * `row` IS THE PRICE OF THAT SHAPE. Every other listing on this dashboard has a natural column that is
     * unique per row — a route path, a bean class, a channel name — and hands it to InMemoryListing as the
     * tiebreak. Flattening N DTOs into their properties destroys that: neither `class` (one row per
     * property of it) nor `key` (`enabled` is declared by half the framework's own DTOs) identifies a row,
     * and a tiebreak that ties is no tiebreak at all. So the identity is synthesised here, once, and the
     * listing orders by it — no TableColumn, no `?sort=row`, nothing drawn.
     *
     * @return list<array{row: string, class: string, prefix: string, key: string, value: string}>
     */
    private function configPropRows(): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('configprops'), 'beans') as $name => $bean) {
            if (! is_array($bean) || ($bean['bound'] ?? false) !== true) {
                continue;
            }

            $class = is_string($bean['class'] ?? null) ? $bean['class'] : (string) $name;
            $prefix = is_string($bean['prefix'] ?? null) ? $bean['prefix'] : '';

            foreach (is_array($bean['properties'] ?? null) ? $bean['properties'] : [] as $key => $value) {
                $rows[] = [
                    'row' => $class.'::'.(string) $key,
                    'class' => $class,
                    'prefix' => $prefix,
                    'key' => (string) $key,
                    'value' => $this->scalar($value),
                ];
            }
        }

        return $rows;
    }

    /**
     * The DTOs that did NOT bind, with the reason already written as a sentence — "it did not bind, and
     * here is why" is the more urgent thing this page can say, so it keeps its own panel.
     *
     * @return list<array{class: string, prefix: string, why: string}>
     */
    private function unboundRows(): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('configprops'), 'beans') as $name => $bean) {
            if (! is_array($bean) || ($bean['bound'] ?? false) === true) {
                continue;
            }

            $profiles = [];
            foreach (is_array($bean['profiles'] ?? null) ? $bean['profiles'] : [] as $profile) {
                $profiles[] = is_string($profile) ? $profile : '';
            }

            $rows[] = [
                'class' => is_string($bean['class'] ?? null) ? $bean['class'] : (string) $name,
                'prefix' => is_string($bean['prefix'] ?? null) ? $bean['prefix'] : '',
                'why' => match (true) {
                    is_string($bean['error'] ?? null) => $bean['error'],
                    $profiles !== [] => 'Requires the '.implode(', ', $profiles).' profile, which is not active.',
                    default => 'Not bound on this boot.',
                },
            ];
        }

        return $rows;
    }

    /**
     * The configured cache stores as rows.
     *
     * @return list<array{name: string, driver: string, default: string}>
     */
    private function cacheRows(): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('caches'), 'caches') as $name => $store) {
            $store = is_array($store) ? $store : [];

            $rows[] = [
                'name' => is_string($store['name'] ?? null) ? $store['name'] : (string) $name,
                'driver' => is_string($store['driver'] ?? null) ? $store['driver'] : '',
                // A sortable value rather than a bool, so "default first" is one click on the column.
                'default' => ($store['default'] ?? false) === true ? 'default' : '',
            ];
        }

        return $rows;
    }

    /**
     * The log channels as rows, each with the level it is configured with.
     *
     * @return list<array{name: string, level: string}>
     */
    private function loggerRows(): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('loggers'), 'loggers') as $name => $logger) {
            $rows[] = [
                'name' => (string) $name,
                'level' => is_array($logger) && is_string($logger['configuredLevel'] ?? null) ? $logger['configuredLevel'] : 'INFO',
            ];
        }

        return $rows;
    }

    /**
     * One #[Scheduled] trigger as a string, with an absent one as the empty string the view draws as `—`.
     *
     * ALL THREE TRIGGERS ARE STRINGS, which is the thing worth writing down here: `ScheduledDescriptor`
     * types `cron`, `fixedRate` and `fixedDelay` as `?string` and Cadence parses the two intervals through
     * `Duration::parse()`, so what the endpoint publishes is `10s` or `5m`, never a count of milliseconds.
     * A normaliser that kept only `is_numeric()` values would blank every interval the scanner has ever
     * produced.
     *
     * THAT SAME FACT IS WHY THE TWO INTERVAL COLUMNS ARE NUMBER COLUMNS THAT DO NOT SORT. Number is the right
     * RENDERING — right-aligned tabular figures line `30s` up over `5m` in a stack — and the wrong
     * ORDERING, because what those cells hold is text. `RowComparator::forColumn()` scans the column,
     * finds a value that is not numeric on its first row, and commits every pair of it to `strnatcasecmp`,
     * which orders a duration by its leading digit: ascending by Fixed rate over `250ms`, `30s`, `5m`,
     * `1h` answers `1h, 5m, 30s, 250ms`, the hour first and the quarter-second last — the real ordering
     * turned inside out under a header that promised it. A header that offers an ordering has to deliver
     * one, so these two do not offer it, the same `sortable: false` `meter()` and `actions()` carry for a
     * column with nothing to order by.
     *
     * Delivering it would take a comparable magnitude per row, and the parser that produces one is
     * `Firefly\Resilience\Duration` — a layer Admin may not reach (Deptrac gives it Kernel, Container,
     * Config, Context, AutoConfigure, Web, Actuator and Data). A second duration parser kept here to order
     * a page that lists a handful of tasks is precisely the drift `RowComparator` exists to prevent, so the
     * ordering stays unoffered rather than reimplemented.
     */
    private function trigger(mixed $value): string
    {
        return $value === null || $value === '' ? '' : $this->scalar($value);
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
            'metrics' => $this->meterRows(),
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
     * The Metrics page's model: one page of meters, plus the scale the bars are drawn against.
     *
     * THE SCALE IS A PROPERTY OF THE WHOLE RESULT SET, NOT OF THE SLICE. A bar rescaled per page would say
     * something different about the same number depending on which page it was drawn on — the largest meter
     * on page 2 would fill its row exactly as the largest meter on page 1 does, though one may be a
     * thousand times the other. So `peak` is taken across every meter the endpoint reported, before the
     * search and the slice, and a narrowed page still draws its meters against the whole registry.
     *
     * The rows are read ONCE and used twice. `meterRows()` is not cheap — it reads the metrics index and
     * then reads every name back, so it is N+1 in-process endpoint calls — and calling it a second time for
     * the peak would double that for a number already in hand.
     *
     * @return array<string,mixed>
     */
    private function metricsPage(Request $request): array
    {
        $meters = $this->meterRows();

        $peak = 0.0;
        foreach ($meters as $meter) {
            $peak = max($peak, $meter['peak']);
        }

        return [
            ...$this->listing(
                $request,
                'metrics',
                $meters,
                TableView::of(
                    // Split on `.` the way a class name splits on its namespace separator:
                    // `http.server.requests` is a leaf under a stem, and the stem is the half that may be
                    // elided when the column runs out of room.
                    TableColumn::qualified('name', 'Meter', weight: 5, separator: '.'),
                    TableColumn::token('statistics', 'Statistic', weight: 2),
                    TableColumn::number('peak', 'Value', ch: 12),
                    TableColumn::meter('Relative'),
                ),
                ['name', 'statistics'],
                'name',
            ),
            'peak' => $peak,
        ];
    }

    /**
     * One row per METER, with its measurements nested.
     *
     * THE LISTING UNIT IS THE METER. A meter's `count`, `total` and `max` are three readings of one thing,
     * and paging by measurement would put `count` on page 3 and the `total` it counts on page 4. So the
     * page sorts, searches and slices meters, and each meter draws however many rows it has — which is also
     * why `statistics` exists: a searchable, sortable projection of the nested rows.
     *
     * `peak` is the same projection for ORDER and for the bar: the largest magnitude this meter reported, so
     * the Value column has one number per row to sort by rather than a nested list, and metricsPage() can
     * take the page-wide scale off the rows it already has.
     *
     * The metrics index returns names only, so each name is read back for its measurements — N in-process
     * calls, the right trade for a dashboard, and it keeps MetricsEndpoint's contract untouched.
     *
     * Each measurement is pre-formatted here (bytes as MB, seconds as ms) because the view must not be
     * doing arithmetic, and the JSON surface must keep returning raw numbers for Prometheus.
     *
     * @return list<array{name: string, statistics: string, peak: float, rows: list<array{statistic: string, value: float, display: string}>}>
     */
    private function meterRows(): array
    {
        $metrics = [];
        foreach ($this->subArray($this->payload('metrics'), 'names') as $name) {
            if (! is_string($name)) {
                continue;
            }

            /** @var array<string,mixed> $detail */
            $detail = $this->reader->read('metrics', [$name]) ?? [];

            $rows = [];
            $peak = 0.0;
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
                // ABSOLUTE, because a gauge may be negative and a bar has no sign: the comparison the page
                // offers is "which of these is large", and -2 GB of free memory is large.
                $peak = max($peak, is_numeric($value) ? abs((float) $value) : 0.0);
            }

            $metrics[] = [
                'name' => $name,
                'statistics' => implode(' ', array_column($rows, 'statistic')),
                'peak' => $peak,
                'rows' => $rows,
            ];
        }

        return $metrics;
    }

    /**
     * Recent HTTP exchanges, newest first, with each duration pre-formatted and the trace id carried through.
     *
     * The row is read in the shape HttpExchange::toArray() actually emits — `uri` (the route template) and an
     * ISO-8601 `timestamp` — with `path` and a numeric timestamp still accepted. This method used to read
     * only the latter pair, which the endpoint never produced, so the page showed an empty path and `—` for
     * the age of every request it listed.
     *
     * @return list<array{method: string, path: string, status: int, duration: string, correlationId: string, traceId: string, timestamp: float, row: string}>
     */
    private function exchanges(): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('httpexchanges'), 'exchanges') as $exchange) {
            if (! is_array($exchange)) {
                continue;
            }

            $duration = $exchange['durationMs'] ?? $exchange['duration'] ?? null;
            $uri = $exchange['uri'] ?? $exchange['path'] ?? null;
            $row = [
                'method' => is_string($exchange['method'] ?? null) ? $exchange['method'] : '',
                'path' => is_string($uri) ? $uri : '',
                'status' => is_numeric($exchange['status'] ?? null) ? (int) $exchange['status'] : 0,
                'duration' => is_numeric($duration) ? Format::milliseconds((float) $duration) : '—',
                'correlationId' => is_string($exchange['correlationId'] ?? null) ? $exchange['correlationId'] : '',
                'traceId' => is_string($exchange['traceId'] ?? null) ? $exchange['traceId'] : '',
                'timestamp' => $this->epoch($exchange['timestamp'] ?? null),
            ];
            $row['row'] = $row['correlationId']."\0".implode("\0", array_map(RowComparator::text(...), $row));
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The OAuth2 page's model, still from ONE read of the `oauth2clients` endpoint.
     *
     * Shape verified against OAuth2ClientsEndpoint: `{issuer: string, authorizations: {processLocal: bool},
     * clients: list<row>}`. The single read is the point AND IT IS WHY `clientRows()` TAKES THE PAYLOAD
     * RATHER THAN READING ITS OWN. AdminEndpointReader::read() does not memoize — it calls handle() again on
     * every call, and re-walks the registry through has() on the way — and this endpoint is not a cheap
     * in-memory introspection like `caches` or `configprops`: it counts the authorizations alive for every
     * client, which the Eloquent service answers with one query per client. Filling the keys with a
     * payload() call each tripled that for nothing, and it also broke the endpoint's own "one clock for the
     * whole sweep" guarantee across the rendered page — the issuer and the counts would each come from a
     * different sweep. AdminOAuth2PageSingleReadTest counts the calls.
     *
     * `processLocalAuthorizations` is carried through because the Active column is otherwise a number that
     * looks server-wide and is not: on the default `memory` driver the counts belong to the worker that
     * rendered this page (see OAuth2ClientsEndpoint). Defaulting to FALSE when the key is absent is
     * deliberate — a warning the payload does not substantiate is its own kind of wrong answer.
     *
     * @return array<string,mixed>
     */
    private function oauth2Page(Request $request): array
    {
        $payload = $this->payload('oauth2clients');
        $processLocal = ($this->subArray($payload, 'authorizations')['processLocal'] ?? null) === true;

        return [
            ...$this->listing(
                $request,
                'oauth2',
                $this->clientRows($payload, $processLocal),
                TableView::of(
                    // `separator: ''` — the two lines of this cell come from two different fields rather
                    // than from splitting one, so the view supplies both halves itself. See TableColumn.
                    TableColumn::qualified('clientId', 'Client', weight: 4, separator: ''),
                    TableColumn::token('authentication', 'Authentication', weight: 3),
                    TableColumn::token('grants', 'Grants', weight: 3),
                    TableColumn::token('scopes', 'Scopes', weight: 3),
                    TableColumn::line('redirects', 'Redirect URIs', weight: 4),
                    TableColumn::token('issuance', 'Tokens', weight: 3),
                    TableColumn::number('active', 'Active', ch: 7),
                ),
                ['clientId', 'clientName', 'grants', 'scopes'],
                'clientId',
            ),
            'issuer' => is_string($payload['issuer'] ?? null) ? $payload['issuer'] : '',
            'processLocalAuthorizations' => $processLocal,
        ];
    }

    /**
     * The OAuth2 clients as flat, sortable rows.
     *
     * Every multi-valued field is space-joined here rather than in the view, for the reason `beanRows()`
     * joins interfaces: a listing can order and search a string and cannot order a list. The `active`
     * column keeps the `—`-for-zero rule exactly (see oauth2Page() for why a zero counted in a per-process
     * store is the one cell that reads as a fact and is not one).
     *
     * IT KEEPS IT AS THE EMPTY STRING THE VIEW DRAWS AS `—`, NEVER AS THE EM-DASH ITSELF, and that is not a
     * stylistic preference: `active` is a sortable number column, and the empty string is the only spelling
     * of an absent cell that orders. `Table\InMemoryListing` ranks `null` and `''` out through
     * `RowComparator::rankEmpty()` BEFORE the direction is applied, so an unvouchable count sits at the end
     * of the listing whichever way the reader runs it. A literal `'—'` is neither, so it would ride through
     * as an ordinary value AND — being non-numeric — commit the whole column to `strnatcasecmp` through
     * `RowComparator::forColumn()`: live counts ordered by their leading digit, and, an em-dash being three
     * bytes above every digit, a descending Active opening on a page of em-dashes. That is precisely the
     * failure RowComparator's docblock is written around, and the one the `Took` column on the HTTP page
     * sidesteps by declining to sort at all.
     *
     * TAKES THE PAYLOAD IT WAS READ FROM, and does not read its own: this endpoint counts authorizations
     * per client and a second read is a second sweep of the store. The processLocal flag rides in beside it
     * for the same reason.
     *
     * `requiresProofKey` (what the endpoints enforce), never `requireProofKey` (the switch the client
     * registered): `require_pkce` and `require_proof_key_for_public_clients` both default to on, so the
     * registered switch reads "no PKCE" for a client whose every authorization request is in fact refused
     * without a code_challenge — the first thing this page is opened to explain. Strictly `=== true`,
     * because both keys are `null` for a client with no authorization_code grant: PKCE and consent are that
     * path's rules, so a machine client sends no code_challenge and reaches no consent screen, and the
     * endpoint says "does not apply" rather than reporting a default nothing enforces. A truthy test would
     * print both labels beside it and send the operator checking two requirements that are not there.
     *
     * @param  array<string,mixed>  $payload
     * @return list<array{clientId: string, clientName: string, authentication: string, grants: string, scopes: string, redirects: string, issuance: string, active: int|string}>
     */
    private function clientRows(array $payload, bool $processLocal): array
    {
        $rows = [];
        foreach ($this->subArray($payload, 'clients') as $client) {
            if (! is_array($client)) {
                continue;
            }

            $issuance = [$this->scalar($client['accessTokenFormat'] ?? ''), $this->scalar($client['accessTokenTtl'] ?? 0).'s'];
            if (($client['requiresProofKey'] ?? null) === true) {
                $issuance[] = 'PKCE';
            }
            if (($client['requireAuthorizationConsent'] ?? null) === true) {
                $issuance[] = 'consent';
            }

            $active = is_numeric($client['activeAuthorizations'] ?? null) ? (int) $client['activeAuthorizations'] : 0;

            $rows[] = [
                'clientId' => $this->scalar($client['clientId'] ?? ''),
                'clientName' => $this->scalar($client['clientName'] ?? ''),
                'authentication' => $this->joined($client['authenticationMethods'] ?? null),
                'grants' => $this->joined($client['grantTypes'] ?? null),
                'scopes' => $this->joined($client['scopes'] ?? null),
                'redirects' => $this->joined($client['redirectUris'] ?? null),
                'issuance' => implode(' · ', $issuance),
                // A `0` counted in a per-process store is the one cell that reads as a fact and is not one:
                // the workers beside this one may be holding a hundred live authorizations for this client,
                // and an operator who reads "none" goes looking for a token endpoint that is refusing
                // nobody. Emptied — nothing counted here, and the view draws the `—` — while a non-zero
                // count is kept as the NUMBER it is, because that one is a floor the store can vouch for
                // and the column is sortable. See this method's docblock for why the em-dash cannot live
                // in the row.
                'active' => $processLocal && $active === 0 ? '' : $active,
            ];
        }

        return $rows;
    }

    /** A multi-valued endpoint field as one searchable, orderable string. */
    private function joined(mixed $values): string
    {
        if (! is_array($values)) {
            return '';
        }

        return implode(' ', array_map(fn (mixed $value): string => $this->scalar($value), $values));
    }

    /**
     * The endpoint's `timestamp` is ISO-8601 UTC with microseconds (HttpExchange::timestampFrom()); the page
     * wants seconds since the epoch for Format::since(). A numeric value is accepted too, so a row from an
     * older cache entry still renders. Anything unparseable is 0.0, which the view shows as `—`.
     */
    private function epoch(mixed $timestamp): float
    {
        if (is_numeric($timestamp)) {
            return (float) $timestamp;
        }

        if (! is_string($timestamp) || $timestamp === '') {
            return 0.0;
        }

        try {
            return (float) (new DateTimeImmutable($timestamp))->format('U.u');
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * Apply a level to one channel and put the reader back where they were.
     *
     * THE POSITION IS CARRIED THROUGH THE POST, which it did not have to be until the Loggers page became a
     * listing. Every channel used to be on one page with no search and no ordering, so there was nothing a
     * redirect to the bare URL could lose; now a reader on page 3, or one who typed `queue` into the search
     * box, would apply a level and land back on an unfiltered first page with their channel somewhere off
     * screen. That is precisely the failure ListingQuery exists to prevent — "a sort link that dropped the
     * filter widens the listing back to every row… and nothing fails when it happens" — and the dashboard
     * already carries state through a redirect next door, in dataWrite().
     *
     * IT IS VALIDATED RATHER THAN TRUSTED. `back` arrives in a form body, so it is caller-supplied and the
     * only honest thing to do with it is check it: it has to BE the Loggers page, or the Loggers page with
     * a query string. Anything else — another dashboard page, a protocol-relative `//elsewhere` — falls
     * back to the bare URL rather than turning an admin form into an open redirect.
     */
    private function setLoggerLevel(Request $request): RedirectResponse
    {
        $name = $request->input('logger');
        $level = $request->input('level');

        if (is_string($name) && $name !== '' && is_string($level) && $level !== '') {
            $this->reader->write('loggers', [$name], ['level' => $level]);
        }

        $page = $this->settings->url('loggers');
        $back = $request->input('back');

        return new RedirectResponse(
            is_string($back) && ($back === $page || str_starts_with($back, $page.'?')) ? $back : $page,
        );
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
                && ($page->slug !== 'data-map' || $this->data->isEnabled())
                && ($page->slug !== 'settings' || $this->console->isEnabled())
                // Datasource needs a database manager to describe. An application with none is a legal
                // LaraFly application, and a menu entry leading to "there is nothing here" is worse than no
                // entry at all.
                && ($page->slug !== 'datasource' || $this->datasource->available())
                && ($page->requires === null || $this->reader->has($page->requires)),
        ));
    }
}
