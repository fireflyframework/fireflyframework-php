# Architecture

LaraFly is **hexagonal**: every subsystem exposes a nominal PHP interface (a *port*) and one or more
*adapters*. Domain and application code depend only on ports. Architectural direction is enforced with
**Deptrac**.

## The kernel layer

`firefly/kernel` is the zero-dependency foundation:

- **`Firefly\Kernel\Lifecycle`** — the `start()`/`stop()` contract for infrastructure adapters.
- **`Firefly\Kernel\Exception\*`** — a product-agnostic typed exception taxonomy.
- **`Firefly\Kernel\Error\*`** — the RFC-7807 `ErrorResponse` model.

## Async → sync (preview)

PyFly (the Python edition) is async-first. LaraFly targets Laravel's synchronous, share-nothing request
lifecycle by default, with Laravel queues/Horizon for async work and optional Octane (Swoole/RoadRunner)
for long-running/WebSocket/SSE workloads. This section is expanded as those subsystems land.
