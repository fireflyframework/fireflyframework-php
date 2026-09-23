<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Web\Route\RouteDescriptor;

/**
 * The dashboard capstone, given a route table that actually PAGES.
 *
 * The pager's paged branch — the page window, Previous/Next, the first/last jumps, the `…` gaps and the
 * `on`/`off` classes — is the third of _pager.blade.php that carries listing state across a page boundary,
 * which is the whole class of bug this wave exists to remove: a page link that drops `?q=` silently widens
 * the listing back to every row and nothing fails when it happens. It is also the third that a seven-row
 * fixture can never render, because `ListingPage::isPaged()` is false whenever there is one page, and seven
 * rows fit on every size the rows-per-page control offers by default.
 *
 * So this case changes TWO things and nothing else. It appends fourteen filler routes to the skeleton's
 * seven, and it OFFERS a page size of 2 — `firefly.admin.table.page-sizes`, seeded through configOverrides()
 * rather than set inside a test, because AdminSettings (and the TableSettings on it) is built once by
 * AdminRouteRegistrar during the boot passes and bound as an instance: a `config()->set()` from a test body
 * arrives after the object that would have read it. Twenty-one rows at two per page is eleven pages, which
 * is the smallest listing on which a five-page window has a gap and a jump on BOTH sides at once.
 *
 * The filler is deliberately `/widgets/…`: `?q=orders` must keep narrowing to the order routes the parent
 * seeds, so no filler path, handler or name may contain the term the search tests look for.
 */
abstract class AdminTablePagedCapstoneTestCase extends AdminTableCapstoneTestCase
{
    /** Eleven pages at two rows a page — see the class docblock for why eleven and not four. */
    public const int ROWS = 21;

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.admin.table.page-sizes' => '2,25,50',
        ];
    }

    /**
     * @return list<RouteDescriptor>
     */
    protected function routes(): array
    {
        $routes = parent::routes();

        // Zero-padded, so the natural ordering the listing applies to the path column and the plain string
        // ordering of a reader's expectation are the same list — `/widgets/2` would sort after `/widgets/14`
        // under one of them and before it under the other, and a page-boundary assertion would be a coin toss.
        for ($n = count($routes) + 1; $n <= self::ROWS; $n++) {
            $suffix = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $routes[] = new RouteDescriptor('GET', '/widgets/'.$suffix, 'App\Http\WidgetController', 'show', 200, 'widgets.show.'.$suffix, []);
        }

        return $routes;
    }
}
