<?php

use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Infrastructure\Catalog\Import\ApprovedCatalogImportTransactionLock;
use Illuminate\Database\Connection;

function sourceForM246(string $relativePath): string
{
    return file_get_contents(dirname(__DIR__, 5).'/'.$relativePath);
}

it('derives the frozen advisory lock key from the canonical artifact identity', function () {
    $canonicalInput = ApprovedCatalogImportTransactionLock::canonicalInput(
        LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
    );

    expect($canonicalInput)->toBe(
        'v1|scope|14:catalog_import|artifact|26:legacy_config_nutrition_v1',
    )->and(hash('sha256', $canonicalInput))->toBe(
        'c2a15e63654c2fff18cedbe0e39a0d6a5465af6ff172aa33bedf354e1bdea70a',
    )->and(ApprovedCatalogImportTransactionLock::keyPair(
        LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
    ))->toBe([-1029611933, 1699491839]);
});

it('derives keys deterministically from UTF-8 artifact bytes and separates artifacts', function () {
    $first = ApprovedCatalogImportTransactionLock::keyPair('catálogo_v1');
    $keyPairMethod = new ReflectionMethod(ApprovedCatalogImportTransactionLock::class, 'keyPair');

    expect(ApprovedCatalogImportTransactionLock::canonicalInput('catálogo_v1'))
        ->toBe('v1|scope|14:catalog_import|artifact|12:catálogo_v1')
        ->and(ApprovedCatalogImportTransactionLock::keyPair('catálogo_v1'))->toBe($first)
        ->and(ApprovedCatalogImportTransactionLock::keyPair('catálogo_v2'))->not->toBe($first)
        ->and($keyPairMethod->getNumberOfParameters())->toBe(1)
        ->and($keyPairMethod->getParameters()[0]->getName())->toBe('logicalArtifactId');
});

it('rejects missing noncanonical and malformed artifact identities', function (string $artifactId) {
    ApprovedCatalogImportTransactionLock::keyPair($artifactId);
})->with([
    'empty' => '',
    'leading whitespace' => ' legacy_config_nutrition_v1',
    'trailing whitespace' => 'legacy_config_nutrition_v1 ',
    'invalid UTF-8' => "legacy_\xC3\x28",
])->throws(InvalidArgumentException::class);

it('executes exactly one blocking transaction-scoped PostgreSQL advisory lock statement', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $connection->shouldReceive('select')->once()->with(
        'select pg_advisory_xact_lock(cast(? as integer), cast(? as integer))',
        [-1029611933, 1699491839],
    )->andReturn([new stdClass]);

    (new ApprovedCatalogImportTransactionLock)->acquire(
        $connection,
        LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
    );
});

it('requires PostgreSQL and an already active outer transaction', function () {
    $nonPostgreSql = Mockery::mock(Connection::class);
    $nonPostgreSql->shouldReceive('getDriverName')->once()->andReturn('sqlite');
    $nonPostgreSql->shouldNotReceive('select');

    expect(fn () => (new ApprovedCatalogImportTransactionLock)->acquire(
        $nonPostgreSql,
        LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
    ))->toThrow(RuntimeException::class, 'requires PostgreSQL');

    $withoutTransaction = Mockery::mock(Connection::class);
    $withoutTransaction->shouldReceive('getDriverName')->once()->andReturn('pgsql');
    $withoutTransaction->shouldReceive('transactionLevel')->once()->andReturn(0);
    $withoutTransaction->shouldNotReceive('select');

    expect(fn () => (new ApprovedCatalogImportTransactionLock)->acquire(
        $withoutTransaction,
        LegacyCatalogArtifactDescriptor::ARTIFACT_ID,
    ))->toThrow(RuntimeException::class, 'requires an active transaction');
});

it('keeps the lock narrow blocking transaction scoped and ordered before inspection', function () {
    $lock = sourceForM246('app/Nutrition/Infrastructure/Catalog/Import/ApprovedCatalogImportTransactionLock.php');
    $useCase = sourceForM246('app/Nutrition/Application/Catalog/Persistence/ApplyApprovedLegacyCatalogImport.php');
    $actorLookup = strpos($useCase, '$actorExists =');
    $lockAcquisition = strpos($useCase, '$this->transactionLock->acquire');
    $inspection = strpos($useCase, '$inspection = $this->inspector->inspect');

    expect((new ReflectionClass(ApprovedCatalogImportTransactionLock::class))->isFinal())->toBeTrue()
        ->and(substr_count($lock, 'pg_advisory_xact_lock'))->toBe(1)
        ->and($lock)->not->toContain(
            'pg_advisory_lock(',
            'pg_try_advisory',
            'pg_advisory_unlock',
            'lockForUpdate',
            'LOCK TABLE',
        )
        ->and($useCase)->toContain('attempts: 1')
        ->and($actorLookup)->toBeInt()
        ->and($lockAcquisition)->toBeInt()
        ->and($inspection)->toBeInt()
        ->and($actorLookup)->toBeLessThan($lockAcquisition)
        ->and($lockAcquisition)->toBeLessThan($inspection);
});
