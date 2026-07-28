<?php

declare(strict_types=1);

namespace Lumen\Infrastructure;

use Firefly\Container\Attributes\Repository;
use Firefly\Data\Repository\EloquentRepository;
use InvalidArgumentException;
use Lumen\Domain\Wallet;

/**
 * The Eloquent ADAPTER for the `WalletRepository` port, bound to the `Wallet` aggregate model.
 *
 * Because this class `implements WalletRepository` (a real PHP interface) in addition to `extends
 * EloquentRepository`, every interface method needs an EXPLICIT body — the framework's own convention of leaning on
 * `EloquentRepository::__call()` (magic dispatch, documented via `@method` docblocks only) does NOT satisfy an
 * `implements` contract; PHP fatals at class-load time if an abstract interface method is left undefined.
 *
 * `save()`/`findById()` keep the PARENT's exact parameter type (`object`/`mixed`) and only narrow the RETURN type to
 * `Wallet` — not the parameter to `Wallet`, even though that is what `WalletRepository` declares. This is a real PHP
 * variance constraint, not a stylistic choice: overriding `EloquentRepository::save(object $entity): object` with a
 * narrower parameter (`Wallet $wallet`) is an invalid override (contravariance requires the override's parameter to
 * be the same type or WIDER than the parent's, never narrower) and fatals at class-load — confirmed empirically
 * while building this adapter. Widening the implementation's parameter back to `object`/`mixed` still satisfies
 * `WalletRepository`'s narrower `Wallet`/`string` parameters, because interface conformance uses the same
 * contravariant-parameter/covariant-return rule: an implementation may accept MORE than the interface promises and
 * return exactly what it promises. An `instanceof` guard narrows the parameter back to `Wallet` before delegating to
 * `parent::save()`, so the generic template parameter on the parent resolves to `Wallet` and the return type lines
 * up with no extra type-override annotation or PHPStan suppression comment needed.
 *
 * `findByOwnerId()` — a derived query with no parent counterpart, so no variance constraint applies — delegates
 * explicitly to the protected `dispatchQuery()` dispatcher (the same engine `__call()` would have reached, just
 * invoked directly so the method has a real body, per the `implements` requirement above).
 *
 * @extends EloquentRepository<Wallet>
 */
#[Repository]
final class EloquentWalletRepository extends EloquentRepository implements WalletRepository
{
    protected string $model = Wallet::class;

    public function save(object $entity): Wallet
    {
        if (! $entity instanceof Wallet) {
            throw new InvalidArgumentException(sprintf('%s::save() only accepts a %s.', self::class, Wallet::class));
        }

        return parent::save($entity);
    }

    public function findById(mixed $id): ?Wallet
    {
        $found = parent::findById($id);

        return $found instanceof Wallet ? $found : null;
    }

    /** @return list<Wallet> */
    public function findByOwnerId(string $ownerId): array
    {
        /** @var list<Wallet> $result */
        $result = $this->dispatchQuery('findByOwnerId', [$ownerId]);

        return $result;
    }
}
