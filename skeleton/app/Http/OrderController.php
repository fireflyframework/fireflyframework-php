<?php

declare(strict_types=1);

namespace App\Http;

use App\Orders\Address;
use App\Orders\Order;
use App\Orders\OrderLine;
use App\Orders\OrderService;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\DeleteMapping;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\PutMapping;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

/**
 * Customer orders: list, read, place, replace and cancel.
 *
 * An order carries the buyer, a shipping address and one or more lines; its `total` is derived from those
 * lines and is never accepted from the client. An unknown id is answered with an RFC-7807 problem document
 * whose `code` is `ORDER_NOT_FOUND`.
 */
#[RestController]
#[RequestMapping('/orders')]
final class OrderController
{
    // NOTE — THE DOCBLOCK ABOVE IS PUBLISHED, THIS COMMENT IS NOT. firefly/openapi uses a controller's
    // class docblock as the tag description in /openapi.json and each action's docblock as that
    // operation's summary and description, so anything written there is read by whoever consumes this
    // API. Line comments like this one are invisible to the generator, which is why the notes on how the
    // framework serves this resource live here.
    //
    // THE SAMPLE REST RESOURCE — what `php artisan make:firefly-controller OrderController` generates,
    // filled in. Read it as five statements about the framework:
    //
    //  * ROUTING. One class-level #[RequestMapping] fixes the collection path; each action adds only its own
    //    suffix and verb. Nothing is registered in routes/web.php — `firefly:cache` compiles all five into
    //    the manifest the dispatcher reads, and `php artisan firefly:routes` lists them.
    //  * BINDING. #[PathVariable] and #[QueryParam] bind AND coerce (`/orders/7` arrives as an int), and
    //    #[RequestBody] decodes JSON into a DTO — including the nested AddressPayload and the list of
    //    OrderLinePayloads, built from a shape table compiled at cache time so the request path never
    //    reflects.
    //  * VALIDATION. #[Valid] runs the compiled constraints BEFORE hydration, so an invalid body is a 422
    //    with per-field errors (`shipTo.postcode`) and never reaches these method bodies. There is no
    //    FormRequest and no `$request->validate(...)` call anywhere.
    //  * STATUS. 201 on `store` and 204 on `destroy` are declared on the mapping, not built by hand; a
    //    `void` action is how you say "no body".
    //  * ERRORS. Nothing here handles a missing order. OrderService throws ResourceNotFoundException and
    //    firefly/web renders the whole FireflyException taxonomy as problem+json at the exception's own
    //    status — a 404 with a stable error code, from zero lines of error handling in this class.
    //
    // WHY IT MAPS INSTEAD OF FORWARDING THE DTO. toOrder() translates the HTTP payloads into App\Orders
    // types rather than passing OrderRequest down into the service. It costs six lines and buys the property
    // that nothing under App\Orders imports anything under App\Http: the use cases can be driven from a
    // console command or a queued job, and the wire format can change without the domain noticing. In a
    // slice this small the two shapes look almost identical — which is exactly when the habit is cheap to
    // form.
    //
    // `/` belongs to App\Http\WelcomeController, a #[Controller] that renders HTML; every action here
    // returns a value the ResponseFactory negotiates into JSON. That is the difference between the two
    // stereotypes.

    /** The largest page a client may ask for, so `?size=100000` cannot force the whole store out at once. */
    private const int MAX_PAGE_SIZE = 100;

    public function __construct(private readonly OrderService $orders) {}

    /**
     * List orders, newest id last, one page at a time.
     *
     * @return array{page: int, size: int, total: int, items: list<Order>}
     */
    #[GetMapping(name: 'orders.index')]
    public function index(
        // #[QueryParam] CARRIES ITS DEFAULT TWICE, and the repetition is load-bearing. RouteScanner compiles
        // the binding's fallback from the ATTRIBUTE, never from the PHP default value: a parameter default
        // is not reachable from the compiled, reflection-free plan the ArgumentResolver reads per request.
        // Write only `int $page = 1` and an absent `?page` binds null, which then fails against the `int` in
        // this very signature — a 500 for a request that merely omitted an optional parameter.
        #[QueryParam(default: 1)] int $page = 1,
        #[QueryParam(default: 20)] int $size = 20,
    ): array {
        return $this->orders->page(max(1, $page), min(self::MAX_PAGE_SIZE, max(1, $size)));
    }

    /** Read one order by id. */
    #[GetMapping('/{id}', name: 'orders.show')]
    public function show(#[PathVariable] int $id): Order
    {
        return $this->orders->find($id);
    }

    /** Place a new order. Responds 201 with the stored order, including its assigned id and its total. */
    #[PostMapping(status: 201, name: 'orders.store')]
    public function store(#[Valid] #[RequestBody] OrderRequest $request): Order
    {
        return $this->orders->place($this->toOrder(null, $request));
    }

    /**
     * Replace an order wholesale, keeping its id. Takes the same body as placing one.
     */
    #[PutMapping('/{id}', name: 'orders.update')]
    public function update(#[PathVariable] int $id, #[Valid] #[RequestBody] OrderRequest $request): Order
    {
        // The same DTO as `store` on purpose: a PUT that accepted a laxer shape than the POST is how a
        // resource ends up with two contradictory schemas in its own OpenAPI document.
        return $this->orders->replace($id, $this->toOrder($id, $request));
    }

    /** Cancel an order. Responds 204 with an empty body. */
    #[DeleteMapping('/{id}', status: 204, name: 'orders.destroy')]
    public function destroy(#[PathVariable] int $id): void
    {
        $this->orders->cancel($id);
    }

    /**
     * The one place the wire format meets the domain.
     *
     * Private, so the RouteScanner — which only reads PUBLIC methods — can never mistake it for an action.
     */
    private function toOrder(?int $id, OrderRequest $request): Order
    {
        return new Order(
            $id,
            $request->customer,
            $request->email,
            new Address(
                $request->shipTo->street,
                $request->shipTo->city,
                $request->shipTo->postcode,
                $request->shipTo->country,
            ),
            array_values(array_map(
                static fn (OrderLinePayload $line): OrderLine => new OrderLine($line->sku, $line->quantity, $line->unitPrice),
                $request->lines,
            )),
        );
    }
}
