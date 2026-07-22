<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Proxy;

use Firefly\Data\Transaction\Attributes\Transactional;
use Illuminate\Support\Facades\DB;

/**
 * A class-level #[Transactional] fixture whose methods exercise every signature shape the generator must copy
 * LSP-faithfully: typed scalar params with defaults, a nullable union param, a variadic, a `void` return and a
 * typed return. `persist`/`persistVoid` write to the `widgets` table so the DatabaseTestCase-backed routing test
 * can observe that the generated override actually dispatches through the interceptor (return + void paths).
 * NOT `final` — the proxy extends it.
 */
#[Transactional]
class SignatureBag
{
    public function scalarDefaults(int $by = 1, string $label = 'x'): int
    {
        return $by + strlen($label);
    }

    public function nullableUnion(int|string|null $value = null): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @return list<string>
     */
    public function variadic(string ...$parts): array
    {
        return array_values($parts);
    }

    public function persist(string $name): int
    {
        DB::table('widgets')->insert(['name' => $name]);

        return (int) DB::table('widgets')->count();
    }

    public function persistVoid(string $name): void
    {
        DB::table('widgets')->insert(['name' => $name]);
    }
}
