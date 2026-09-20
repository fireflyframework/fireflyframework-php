<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Kernel\Exception\Infrastructure\BadSqlGrammarException;
use Firefly\Kernel\Exception\Infrastructure\DataAccessResourceFailureException;
use Firefly\Kernel\Exception\Infrastructure\DataIntegrityViolationException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Firefly\Kernel\Exception\Infrastructure\EmptyResultDataAccessException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable()->unique();
    });
});

function translatingRepository(?PersistenceExceptionTranslator $translator = null): RecordRepository
{
    $manifest = (new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository',
    ]);

    return new RecordRepository($manifest, null, $translator);
}

it('throws DuplicateKeyException from save() on a unique violation, with the QueryException underneath', function () {
    $repo = translatingRepository();
    $repo->save(new Record(['status' => 'open', 'amount' => 1, 'email' => 'taken@x.test']));

    try {
        $repo->save(new Record(['status' => 'open', 'amount' => 2, 'email' => 'taken@x.test']));
        $this->fail('expected a DuplicateKeyException');
    } catch (DuplicateKeyException $e) {
        expect($e->getPrevious())->toBeInstanceOf(QueryException::class)
            ->and($e->getMessage())->not->toContain('taken@x.test')
            ->and($e->httpStatus())->toBe(409)
            ->and($e->extensions())->toBe(['sqlState' => '23000']);
    }
});

it('throws DataIntegrityViolationException for a NOT NULL violation', function () {
    expect(fn () => translatingRepository()->save(new Record(['amount' => 1])))
        ->toThrow(DataIntegrityViolationException::class);
});

it('throws BadSqlGrammarException from a #[Query] method whose SQL names a missing table', function () {
    // The fixture's #[Query] selects from `records`; drop the table so the very same method now fails at the driver.
    Schema::drop('records');

    expect(fn () => translatingRepository()->findByEmailRaw('a@x.test'))->toThrow(BadSqlGrammarException::class);
});

it('throws DataAccessResourceFailureException when the model\'s connection cannot be opened', function () {
    config()->set('database.connections.missing', ['driver' => 'sqlite', 'database' => '/nonexistent/firefly/records.sqlite', 'prefix' => '']);
    $model = new Record;
    $model->setConnection('missing');
    $repo = translatingRepository();

    expect(fn () => $repo->save($model))->toThrow(DataAccessResourceFailureException::class);
});

it('getById() throws EmptyResultDataAccessException where findById() returns null', function () {
    $repo = translatingRepository();
    $saved = $repo->save(new Record(['status' => 'open', 'amount' => 1]));

    expect($repo->getById($saved->id)->id)->toBe($saved->id)
        ->and($repo->findById(9999))->toBeNull()
        ->and(fn () => $repo->getById(9999))->toThrow(EmptyResultDataAccessException::class);
});

it('lets the raw QueryException through when translation is disabled', function () {
    $repo = translatingRepository(new PersistenceExceptionTranslator(enabled: false));
    $repo->save(new Record(['status' => 'open', 'amount' => 1, 'email' => 'taken@x.test']));

    expect(fn () => $repo->save(new Record(['status' => 'open', 'amount' => 2, 'email' => 'taken@x.test'])))
        ->toThrow(QueryException::class);
});

it('translates a derived query and the paged/sorted/specification reads too', function () {
    Schema::drop('records');
    $repo = translatingRepository();

    expect(fn () => $repo->countByStatus('open'))->toThrow(BadSqlGrammarException::class)
        ->and(fn () => $repo->findAll())->toThrow(BadSqlGrammarException::class)
        ->and(fn () => $repo->count())->toThrow(BadSqlGrammarException::class)
        ->and(fn () => $repo->findPaged(Pageable::of(1, 5)))->toThrow(BadSqlGrammarException::class)
        ->and(fn () => $repo->findSorted(Sort::by('id')))->toThrow(BadSqlGrammarException::class)
        ->and(fn () => $repo->deleteAll())->toThrow(BadSqlGrammarException::class)
        ->and(fn () => $repo->existsById(1))->toThrow(BadSqlGrammarException::class)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
