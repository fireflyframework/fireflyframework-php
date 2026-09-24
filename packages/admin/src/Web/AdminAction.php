<?php

declare(strict_types=1);

namespace Firefly\Admin\Web;

use DateTimeImmutable;
use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Admin\AdminEndpointReader;
use Firefly\Admin\AdminSettings;
use Firefly\Admin\BeanGraph;
use Firefly\Admin\Data\ConnectionWizard;
use Firefly\Admin\Data\DataBrowser;
use Firefly\Admin\Data\DataFilter;
use Firefly\Admin\Data\DataMap;
use Firefly\Admin\Data\DatasourceReport;
use Firefly\Admin\Format;
use Firefly\Admin\Settings\FeatureToggle;
use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Admin\Table\InMemoryListing;
use Firefly\Admin\Table\ListingPage;
use Firefly\Admin\Table\ListingQuery;
use Firefly\Admin\Table\TableColumn;
use Firefly\Admin\Table\TableView;
use Firefly\Context\Scan\AppScan;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        $page = (int) ($request->query('page') ?? 1);
        $sort = $request->query('sort');
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $search = $request->query('q');

        $perPage = $request->query('size');
        $filters = $this->filters($request);

        $listing = $this->data->list(
            $slug,
            max(1, $page),
            is_string($perPage) && ctype_digit($perPage) ? (int) $perPage : null,
            is_string($sort) && $sort !== '' ? $sort : null,
            $direction,
            is_string($search) && $search !== '' ? $search : null,
            $filters,
        );

        return $this->html($this->render('data-list', [
            'listing' => $listing,
            'writable' => $this->data->isWritable(),
            'relations' => $this->data->relationsFor($slug),
            'operators' => DataFilter::operators(),
        ]), 200);
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
            'metrics' => ['metrics' => $this->metrics()],
            'http' => ['exchanges' => $this->exchanges()],
            'beans' => $this->listing(
                $request,
                'beans',
                $this->beanRows(),
                TableView::of(
                    TableColumn::qualified('class', 'Class', weight: 5),
                    TableColumn::token('stereotype', 'Stereotype', weight: 2),
                    TableColumn::token('scope', 'Scope', weight: 1.5),
                    TableColumn::token('name', 'Name', weight: 2),
                    TableColumn::text('interfaces', 'Implements', weight: 3, sortable: true),
                ),
                // `interfacesQualified` is searched but has no column: it is the same list the Implements
                // column draws, spelled out, and the cell carries it on its `title`. See beanRows().
                ['class', 'stereotype', 'scope', 'name', 'interfaces', 'interfacesQualified'],
                'class',
            ),
            // #[ConfigProperties] DTOs are bound and injectable but are neither scanned as components nor
            // produced by a factory, so the beans catalogue alone cannot see them — they arrived as
            // unresolved dependencies instead of as the beans they are.
            'graph' => ['graph' => BeanGraph::build(
                $this->listOf('beans', 'beans'),
                $this->subArray($this->payload('configprops'), 'beans'),
            )],
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
                'path',
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
            'oauth2' => $this->oauth2(),
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
                defaultSort: 'key',
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
                    defaultSort: 'name',
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
                    defaultSort: 'name',
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
     * `$defaultSort` is left null for a listing whose natural order IS its tiebreak: InMemoryListing falls
     * back to the tiebreak column when no sort was asked for, so declaring the same key as the default sort
     * would change nothing about the rows and a great deal about the URLs — `meaningful()` omits a
     * parameter that is already at its default, so every header link would lose its own `?sort=` and a
     * reader could not tell the sorted column from the rest by looking at where the link goes.
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

        $applied = $this->listing($request, 'conditions', $this->conditionRows('positiveMatches'), $view, ['class', 'condition'], 'class', qualifier: 'pos');
        $backed = $this->listing($request, 'conditions', $this->conditionRows('negativeMatches'), $view, ['class', 'condition'], 'class', qualifier: 'neg');

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
        $bound = $this->listing($request, 'configprops', $this->configPropRows(), $boundView, ['class', 'prefix', 'key', 'value'], 'row', defaultSort: 'class', qualifier: 'props');
        $unbound = $this->listing($request, 'configprops', $this->unboundRows(), $unboundView, ['class', 'prefix', 'why'], 'class', defaultSort: 'class', qualifier: 'unbound');

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
     * @return list<array{httpMethod: string, path: string, handler: string, name: string}>
     */
    private function mappingRows(): array
    {
        $rows = [];
        foreach ($this->listOf('mappings', 'mappings') as $route) {
            if (! is_array($route)) {
                continue;
            }

            $rows[] = [
                'httpMethod' => is_string($route['httpMethod'] ?? null) ? $route['httpMethod'] : '',
                'path' => is_string($route['path'] ?? null) ? $route['path'] : '',
                'handler' => is_string($route['handler'] ?? null) ? $route['handler'] : '',
                'name' => is_string($route['name'] ?? null) ? $route['name'] : '',
            ];
        }

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
     * @return list<array{class: string, condition: string}>
     */
    private function conditionRows(string $key): array
    {
        $rows = [];
        foreach ($this->subArray($this->payload('conditions'), $key) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'class' => is_string($row['class'] ?? null) ? $row['class'] : '',
                'condition' => is_string($row['condition'] ?? null) ? $row['condition'] : '',
            ];
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
     * Recent HTTP exchanges, newest first, with each duration pre-formatted and the trace id carried through.
     *
     * The row is read in the shape HttpExchange::toArray() actually emits — `uri` (the route template) and an
     * ISO-8601 `timestamp` — with `path` and a numeric timestamp still accepted. This method used to read
     * only the latter pair, which the endpoint never produced, so the page showed an empty path and `—` for
     * the age of every request it listed.
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
            $uri = $exchange['uri'] ?? $exchange['path'] ?? null;
            $rows[] = [
                'method' => is_string($exchange['method'] ?? null) ? $exchange['method'] : '',
                'path' => is_string($uri) ? $uri : '',
                'status' => is_numeric($exchange['status'] ?? null) ? (int) $exchange['status'] : 0,
                'duration' => is_numeric($duration) ? Format::milliseconds((float) $duration) : '—',
                'correlationId' => is_string($exchange['correlationId'] ?? null) ? $exchange['correlationId'] : '',
                'traceId' => is_string($exchange['traceId'] ?? null) ? $exchange['traceId'] : '',
                'timestamp' => $this->epoch($exchange['timestamp'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * The OAuth2 page's model, from ONE read of the `oauth2clients` endpoint.
     *
     * Shape verified against OAuth2ClientsEndpoint: `{issuer: string, authorizations: {processLocal: bool},
     * clients: list<row>}`. The single read is the point. AdminEndpointReader::read() does not memoize — it
     * calls handle() again on every call, and re-walks the registry through has() on the way — and this
     * endpoint is not a cheap in-memory introspection like `caches` or `configprops`: it counts the
     * authorizations alive for every client, which the Eloquent service answers with one query per client.
     * Filling the keys with a payload() call each tripled that for nothing, and it also broke the endpoint's
     * own "one clock for the whole sweep" guarantee across the rendered page — the issuer and the counts would
     * each come from a different sweep.
     *
     * `processLocalAuthorizations` is carried through because the Active column is otherwise a number that
     * looks server-wide and is not: on the default `memory` driver the counts belong to the worker that
     * rendered this page (see OAuth2ClientsEndpoint). Defaulting to FALSE when the key is absent is
     * deliberate — a warning the payload does not substantiate is its own kind of wrong answer.
     *
     * @return array<string,mixed>
     */
    private function oauth2(): array
    {
        $payload = $this->payload('oauth2clients');
        $issuer = $payload['issuer'] ?? null;

        return [
            'issuer' => is_string($issuer) ? $issuer : '',
            'clients' => $this->subArray($payload, 'clients'),
            'processLocalAuthorizations' => ($this->subArray($payload, 'authorizations')['processLocal'] ?? null) === true,
        ];
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
