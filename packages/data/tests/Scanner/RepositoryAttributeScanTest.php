<?php

declare(strict_types=1);

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\RecordNickname;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Fixtures\Repository\RecordSummary;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

function repositoryManifest(): TransactionalManifest
{
    return (new TransactionalScanner)->scan(['Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository']);
}

it('records #[Modifying] with its transaction requirement, and leaves the #[Query] map exactly as it was', function () {
    $manifest = repositoryManifest();

    expect($manifest->repositoryMethod(RecordRepository::class, 'closeSmall'))->toBe([
        'modifying' => ['requiresTransaction' => true],
        'projection' => null,
        'lock' => null,
        'entityGraph' => null,
        'returns' => null,
    ])
        ->and($manifest->repositoryMethod(RecordRepository::class, 'purgeStatus')['modifying'] ?? null)->toBe(['requiresTransaction' => false])
        ->and($manifest->queriesFor(RecordRepository::class)['closeSmall'])->toBe(['sql' => 'update records set status = :status where amount < :amount', 'native' => false])
        // a plain #[Query] method has no repository row: nothing to record beyond its SQL
        ->and($manifest->repositoryMethod(RecordRepository::class, 'findByEmailRaw'))->toBeNull();
});

it('reflects the projection DTO\'s constructor ONCE, at scan time, into columns and typed parameters', function () {
    $row = repositoryManifest()->repositoryMethod(RecordRepository::class, 'findByStatusOrderByAmountAsc');

    expect($row['projection'] ?? null)->toBe([
        'dto' => RecordSummary::class,
        'columns' => ['id', 'email', 'amount'],
        'parameters' => [
            ['name' => 'id', 'column' => 'id', 'type' => 'int', 'nullable' => false, 'optional' => false],
            ['name' => 'email', 'column' => 'email', 'type' => 'string', 'nullable' => true, 'optional' => false],
            ['name' => 'amount', 'column' => 'amount', 'type' => 'int', 'nullable' => false, 'optional' => false],
        ],
    ])
        ->and(repositoryManifest()->repositoryMethod(RecordRepository::class, 'findByAmountLessThan')['projection']['dto'] ?? null)->toBe(RecordNickname::class);
});

it('records #[Lock], #[EntityGraph] and a Slice return type', function () {
    $manifest = repositoryManifest();

    expect($manifest->repositoryMethod(RecordRepository::class, 'findByEmail')['lock'] ?? null)->toBe('PESSIMISTIC_WRITE')
        ->and($manifest->repositoryMethod(RecordRepository::class, 'findByAmountBetween')['lock'] ?? null)->toBe('PESSIMISTIC_READ')
        ->and($manifest->repositoryMethod(RecordRepository::class, 'findByStatusOrderByIdDesc')['entityGraph'] ?? null)->toBe(['value' => null, 'attributePaths' => ['entries']])
        ->and($manifest->repositoryMethod(RecordRepository::class, 'findAll')['entityGraph'] ?? null)->toBe(['value' => 'Record.full', 'attributePaths' => []])
        ->and($manifest->repositoryMethod(RecordRepository::class, 'findByStatusOrderByIdAsc')['returns'] ?? null)->toBe('slice')
        ->and(array_keys($manifest->repositoryMethodsFor(RecordRepository::class)))->toBe([
            'closeSmall', 'findAll', 'findByAmountBetween', 'findByAmountLessThan', 'findByEmail', 'findByStatusOrderByAmountAsc', 'findByStatusOrderByIdAsc', 'findByStatusOrderByIdDesc', 'purgeStatus', 'summariesByStatusRaw',
        ]);
});

it('round-trips the repository rows through the compiled file and loads a file without the new keys', function () {
    $manifest = repositoryManifest();
    $path = sys_get_temp_dir().'/firefly-repo-manifest-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new TransactionalManifestCompiler)->write($manifest, $path);

        expect(TransactionalManifest::load($path)->toArray())->toBe($manifest->toArray())
            ->and(TransactionalManifest::load($path)->repositoryMethod(RecordRepository::class, 'closeSmall'))->toBe($manifest->repositoryMethod(RecordRepository::class, 'closeSmall'));
    } finally {
        @unlink($path);
    }

    // A transactional.php compiled before this wave has only proxies + queries.
    $legacy = TransactionalManifest::fromArray(['proxies' => [], 'queries' => []]);
    expect($legacy->repositoryMethodsFor(RecordRepository::class))->toBe([])
        ->and($legacy->listeners())->toBe([]);
});

it('refuses the invalid shapes at scan time', function (string $dir, string $fragment) {
    expect(fn () => (new TransactionalScanner)->scan(['Firefly\\Data\\Tests\\Fixtures\\Invalid\\'.$dir.'\\' => dirname(__DIR__).'/Fixtures/Invalid/'.$dir]))
        ->toThrow(ConfigurationException::class, $fragment);
})->with([
    'modifying on a select' => ['ModifyingSelect', 'carries a SELECT'],
    'modifying without a query' => ['ModifyingWithoutQuery', 'needs a #[Query]'],
    'lock on a query' => ['LockOnQuery', 'cannot be applied to a #[Query]'],
]);
