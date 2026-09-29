<?php

declare(strict_types=1);

namespace Firefly\Admin\Table;

use Illuminate\Http\Request;

/**
 * One listing's position in its own data: which page, how many rows, ordered by what, narrowed by what —
 * and, the half that matters, how to write the URL of a NEIGHBOURING position.
 *
 * This is Spring Data's `Pageable` with the web layer's `PageableHandlerMethodArgumentResolver` folded into
 * it, and the qualifier is the same idea `@Qualifier("pos") Pageable` gives a controller that resolves two
 * of them: the parameter names become `pos_page`, `pos_sort`, and two listings can share a page without
 * paging each other.
 *
 * EVERY LINK IS REBUILT FROM PARSED VALUES. The views this replaces concatenated four strings —
 * `$keepFilter`, `$keepSearch`, `$keepSize`, `$keepSort` — into every href, with a comment beside them
 * asking the next author not to forget one. That is a contract no reviewer can hold: a sort link that
 * dropped the filter widens the listing back to every row, which reads as rows appearing from nowhere, and
 * nothing fails when it happens. Here the state lives in one place, `link()` is the only way out, and an
 * override names a parameter rather than editing a string.
 *
 * DEFAULTS ARE OMITTED, DELIBERATELY. `/firefly/mappings` is the URL of an unsorted first page, not
 * `/firefly/mappings?page=1&size=50&sort=path&dir=asc`. A URL that spells out its defaults is a URL that
 * cannot be shortened by hand, and it also pins the defaults: a bookmark taken today would keep rendering
 * 50 rows after an operator set `page-size` to 100.
 *
 * EVERY BOUND IS APPLIED HERE, NOT DOWNSTREAM. The page is clamped low and never redirected — answering 302
 * to a bookmark is how the back button stops working — the size must be one the settings OFFER, the sort
 * column must be one the caller published (an unknown one is dropped, never quoted into an ORDER BY), the
 * direction is `desc` only for that exact string, and the search term is trimmed and length-capped so the
 * URL cannot carry a kilobyte into a LIKE.
 */
final readonly class ListingQuery
{
    /**
     * A term longer than this is not a search, it is a payload. 120 characters is past any identifier,
     * path or class name a dashboard lists, and the cap is applied before the term reaches a query at all.
     */
    public const int MAX_SEARCH_LENGTH = 120;

    /**
     * @param  list<string>  $sortable  the column keys this listing will accept in `?sort=`
     * @param  array<string, string|list<string>>  $carried  parameters this listing does not own and must not lose
     */
    public function __construct(
        public TableSettings $settings,
        public string $path,
        public int $page,
        public int $size,
        public ?string $sort,
        public string $direction,
        public ?string $search,
        public array $sortable = [],
        public array $carried = [],
        public string $qualifier = '',
        public ?string $defaultSort = null,
        public string $defaultDirection = 'asc',
    ) {}

    /**
     * @param  list<string>  $sortable
     * @param  array<string, string|list<string>>  $carried
     */
    public static function fromRequest(
        Request $request,
        TableSettings $settings,
        string $path,
        array $sortable = [],
        ?string $defaultSort = null,
        string $defaultDirection = 'asc',
        array $carried = [],
        string $qualifier = '',
    ): self {
        $read = static function (string $parameter) use ($request, $qualifier): ?string {
            $value = $request->query($qualifier === '' ? $parameter : $qualifier.'_'.$parameter);

            return is_string($value) ? $value : null;
        };

        $page = $read('page');
        $size = $read('size');
        $requested = $read('sort');
        $search = trim($read('q') ?? '');

        $sort = $requested !== null && in_array($requested, $sortable, true) ? $requested : $defaultSort;

        return new self(
            settings: $settings,
            path: $path,
            page: $page !== null && ctype_digit($page) ? max(1, (int) $page) : 1,
            size: $settings->clamp($size !== null && ctype_digit($size) ? (int) $size : null),
            sort: $sort,
            direction: $sort === null ? $defaultDirection : match ($read('dir')) {
                'desc' => 'desc',
                'asc' => 'asc',
                default => $defaultDirection,
            },
            search: $search === '' ? null : mb_substr($search, 0, self::MAX_SEARCH_LENGTH),
            sortable: $sortable,
            carried: $carried,
            qualifier: $qualifier,
            defaultSort: $defaultSort,
            defaultDirection: $defaultDirection,
        );
    }

    /**
     * This listing's URL with some of its state replaced. An override is keyed by the UNQUALIFIED parameter
     * name and `null` removes it, so `link(['page' => null])` is "the same view, from the top".
     *
     * @param  array<string, string|int|null>  $overrides
     */
    public function link(array $overrides = []): string
    {
        /** @var array<string, string|int|null> $state */
        $state = [
            'q' => $this->search,
            'sort' => $this->sort,
            'dir' => $this->direction,
            'size' => $this->size,
            'page' => $this->page,
            ...$overrides,
        ];

        $parameters = $this->carried;
        foreach ($this->meaningful($state) as $parameter => $value) {
            $parameters[$this->name($parameter)] = $value;
        }

        $query = http_build_query($parameters);

        return $query === '' ? $this->path : $this->path.'?'.$query;
    }

    /** The link the column header points at: this column's ordering, from the first page. */
    public function sortLink(string $column): string
    {
        return $this->link(['sort' => $column, 'dir' => $this->nextDirection($column), 'page' => null]);
    }

    public function nextDirection(string $column): string
    {
        return $this->isSortedBy($column) && $this->direction === 'asc' ? 'desc' : 'asc';
    }

    public function isSortedBy(string $column): bool
    {
        return $this->sort !== null && $this->sort === $column;
    }

    /** The arrow a sorted header carries — empty for every other column, so the page has exactly one. */
    public function indicator(string $column): string
    {
        return $this->isSortedBy($column) ? ($this->direction === 'asc' ? '↑' : '↓') : '';
    }

    public function isFiltered(): bool
    {
        return $this->search !== null;
    }

    /**
     * The state a GET search form must re-submit, as hidden inputs.
     *
     * `q` is absent because the form's own input supplies it, and `page` is absent because a new search
     * starts at the top — page 4 of a result set that no longer exists is an empty table with a pager.
     *
     * @return list<array{name: string, value: string}>
     */
    public function hiddenFields(): array
    {
        $fields = [];

        foreach ($this->carried as $parameter => $value) {
            foreach (is_array($value) ? $value : [$value] as $one) {
                $fields[] = ['name' => is_array($value) ? $parameter.'[]' : $parameter, 'value' => $one];
            }
        }

        foreach ($this->meaningful(['sort' => $this->sort, 'dir' => $this->direction, 'size' => $this->size]) as $parameter => $value) {
            $fields[] = ['name' => $this->name($parameter), 'value' => $value];
        }

        return $fields;
    }

    /**
     * This listing's own parameters, qualified — what the OTHER listing on the same page has to carry.
     *
     * @return array<string, string>
     */
    public function own(): array
    {
        $own = [];
        foreach ($this->meaningful(['q' => $this->search, 'sort' => $this->sort, 'dir' => $this->direction, 'size' => $this->size, 'page' => $this->page]) as $parameter => $value) {
            $own[$this->name($parameter)] = $value;
        }

        return $own;
    }

    /** @param  array<string, string|list<string>>  $parameters */
    public function carrying(array $parameters): self
    {
        return new self(
            settings: $this->settings,
            path: $this->path,
            page: $this->page,
            size: $this->size,
            sort: $this->sort,
            direction: $this->direction,
            search: $this->search,
            sortable: $this->sortable,
            carried: [...$this->carried, ...$parameters],
            qualifier: $this->qualifier,
            defaultSort: $this->defaultSort,
            defaultDirection: $this->defaultDirection,
        );
    }

    /**
     * The same listing at the size it was actually served.
     *
     * THIS EXISTS BECAUSE ONE SIDE ASKS AND THE OTHER SERVES. Every actuator listing is sliced by
     * `InMemoryListing` at exactly `$size`, so for those this is never called. The data browser is the
     * caller: `DataBrowser::list()` applies `firefly.admin.data.max-page-size` to whatever it is handed, and
     * a page that merely ASSUMED the two agree would be assuming something it cannot see from the outside.
     * `TableSettings::boundedBy()` composes that cap into the settings this query was parsed against
     * precisely so they do agree, and this is the line that makes the agreement a fact rather than a hope.
     * Were they ever to part, every number a pager draws — the range readout, the last page, whether `Next`
     * is live — would still be computed from the size that was refused: over a served 50 a query stating 100
     * claims half as many pages as there are and disables `Next` with the second half of the table
     * unreached. So the query is re-stated at the size that was served, and every link it then writes
     * carries that size rather than the one it asked for. A re-stated size is the one size on this object
     * that the offered set did not choose, which is why the rows-per-page control renders
     * `TableSettings::offering()` rather than the set itself.
     */
    public function sized(int $size): self
    {
        return $size === $this->size ? $this : new self(
            settings: $this->settings,
            path: $this->path,
            page: $this->page,
            size: $size,
            sort: $this->sort,
            direction: $this->direction,
            search: $this->search,
            sortable: $this->sortable,
            carried: $this->carried,
            qualifier: $this->qualifier,
            defaultSort: $this->defaultSort,
            defaultDirection: $this->defaultDirection,
        );
    }

    /**
     * The parameters worth writing down: everything that is set and is not already the default.
     *
     * `dir` is the subtle one. With no sort at all the direction says nothing, so its "default" is taken to
     * be whatever it currently is and it drops out; with a sort, it is written only when it differs from
     * the listing's declared default — which is how `/firefly/http` stays the URL of the newest-first page
     * it opens on.
     *
     * @param  array<string, string|int|null>  $state
     * @return array<string, string>
     */
    private function meaningful(array $state): array
    {
        $sort = $state['sort'] ?? null;

        $meaningful = [];
        foreach ($state as $parameter => $value) {
            $default = match ($parameter) {
                'sort' => $this->defaultSort,
                'dir' => $sort === null || $sort === '' ? $value : $this->defaultDirection,
                'size' => $this->settings->pageSize,
                'page' => 1,
                default => null,
            };

            if ($value !== null && $value !== '' && $value !== $default) {
                $meaningful[$parameter] = (string) $value;
            }
        }

        return $meaningful;
    }

    private function name(string $parameter): string
    {
        return $this->qualifier === '' ? $parameter : $this->qualifier.'_'.$parameter;
    }
}
