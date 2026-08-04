<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\CatalogImportDeterministicIdentity;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

final class ApprovedCatalogImportTransactionLock
{
    private const LOCK_SCOPE = 'catalog_import';

    public function acquire(Connection $connection, string $logicalArtifactId): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The approved catalog import transaction lock requires PostgreSQL.');
        }

        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('The approved catalog import transaction lock requires an active transaction.');
        }

        [$firstKey, $secondKey] = self::keyPair($logicalArtifactId);

        $connection->select(
            'select pg_advisory_xact_lock(cast(? as integer), cast(? as integer))',
            [$firstKey, $secondKey],
        );
    }

    public static function canonicalInput(string $logicalArtifactId): string
    {
        self::ensureValidLogicalArtifactId($logicalArtifactId);

        return 'v1|scope|'.CatalogImportDeterministicIdentity::canonicalComponent(self::LOCK_SCOPE)
            .'|artifact|'.CatalogImportDeterministicIdentity::canonicalComponent($logicalArtifactId);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function keyPair(string $logicalArtifactId): array
    {
        $digest = hash('sha256', self::canonicalInput($logicalArtifactId), true);

        /** @var array{first: int, second: int} $unsignedKeys */
        $unsignedKeys = unpack('Nfirst/Nsecond', substr($digest, 0, 8));

        return [
            self::toSignedInteger($unsignedKeys['first']),
            self::toSignedInteger($unsignedKeys['second']),
        ];
    }

    private static function ensureValidLogicalArtifactId(string $logicalArtifactId): void
    {
        if ($logicalArtifactId === '' || trim($logicalArtifactId) !== $logicalArtifactId) {
            throw new InvalidArgumentException('The logical artifact identifier must be a non-empty canonical string.');
        }

        if (! mb_check_encoding($logicalArtifactId, 'UTF-8')) {
            throw new InvalidArgumentException('The logical artifact identifier must be valid UTF-8.');
        }
    }

    private static function toSignedInteger(int $unsignedInteger): int
    {
        return $unsignedInteger >= 0x80000000
            ? $unsignedInteger - 0x100000000
            : $unsignedInteger;
    }
}
