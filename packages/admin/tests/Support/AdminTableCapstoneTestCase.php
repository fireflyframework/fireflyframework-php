<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support;

use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Foundation\Application;

/**
 * The dashboard capstone, given a route table worth listing.
 *
 * AdminCapstoneTestCase boots no application controllers, so its RouteManifest is EMPTY and
 * `/firefly/mappings` renders the "No routes mapped" empty state — which proves the empty state and
 * nothing else. A listing that pages, sorts and searches has to be asserted against rows, so this subclass
 * binds the manifest itself: the same seam WebServiceProvider binds when a scan or a compiled artifact
 * produced one, so MappingsEndpoint, AdminAction, the view and the router all see exactly what they would
 * see in an application. Bound in defineFireflyEnvironment, which Testbench runs while the application is
 * being created — before WebServiceProvider::register() asks `bound()` — so this instance wins rather than
 * racing the scanner.
 *
 * The rows MIRROR THE SKELETON'S OWN CONTROLLERS, because tests/Browser/AdminTablesTest.php drives the
 * shipped skeleton in Chromium and asserts the same facts in pixels: `/greetings/{name}` (the path that
 * rendered as six stacked lines), `DELETE /orders/{id}` (the verb that clipped), and a handful of orders
 * routes for `?q=orders` to narrow to. Two assertions about one application beat two applications.
 */
abstract class AdminTableCapstoneTestCase extends AdminCapstoneTestCase
{
    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $app->instance(RouteManifest::class, new RouteManifest([
            new RouteDescriptor('GET', '/greetings/{name}', 'App\Http\GreetingController', 'show', 200, 'greetings.show', []),
            new RouteDescriptor('GET', '/', 'App\Http\WelcomeController', 'index', 200, 'welcome', [], html: true),
            new RouteDescriptor('GET', '/orders', 'App\Http\OrderController', 'index', 200, 'orders.index', []),
            new RouteDescriptor('GET', '/orders/{id}', 'App\Http\OrderController', 'show', 200, 'orders.show', []),
            new RouteDescriptor('POST', '/orders', 'App\Http\OrderController', 'store', 201, 'orders.store', []),
            new RouteDescriptor('PUT', '/orders/{id}', 'App\Http\OrderController', 'update', 200, 'orders.update', []),
            new RouteDescriptor('DELETE', '/orders/{id}', 'App\Http\OrderController', 'destroy', 204, 'orders.destroy', []),
        ]));
    }
}
