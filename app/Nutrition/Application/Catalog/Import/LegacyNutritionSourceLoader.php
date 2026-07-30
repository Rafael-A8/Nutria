<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportPlanningException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedLegacyNutritionSource;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;
use Throwable;

final class LegacyNutritionSourceLoader
{
    public const APPROVED_ARTIFACT_PATH = 'config/nutrition.php';

    public function load(
        string $repositoryRoot,
        string $artifactPath,
        SourceArtifactChecksum $expectedChecksum,
    ): LoadedLegacyNutritionSource {
        if ($artifactPath !== self::APPROVED_ARTIFACT_PATH) {
            throw new LegacyNutritionImportPlanningException('The requested legacy source path is unsupported.');
        }

        $canonicalRepositoryRoot = realpath($repositoryRoot);

        if ($canonicalRepositoryRoot === false || ! is_dir($canonicalRepositoryRoot)) {
            throw new LegacyNutritionImportPlanningException('The repository root is unavailable.');
        }

        $requestedPath = $canonicalRepositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $artifactPath);
        $canonicalSourcePath = realpath($requestedPath);

        if (
            $canonicalSourcePath === false
            || ! is_file($canonicalSourcePath)
            || ! is_readable($canonicalSourcePath)
            || ! str_starts_with($canonicalSourcePath, $canonicalRepositoryRoot.DIRECTORY_SEPARATOR)
        ) {
            throw new LegacyNutritionImportPlanningException('The approved legacy source is unreadable.');
        }

        $rawBytes = @file_get_contents($canonicalSourcePath);

        if ($rawBytes === false) {
            throw new LegacyNutritionImportPlanningException('The approved legacy source bytes could not be read.');
        }

        try {
            $expectedChecksum->assertMatchesRawBytes($rawBytes);
        } catch (Throwable $exception) {
            throw new LegacyNutritionImportPlanningException(
                'The approved legacy source checksum does not match the expected SHA-256.',
                previous: $exception,
            );
        }

        $initialBufferLevel = ob_get_level();
        ob_start();

        try {
            $payload = (static fn (string $sourcePath): mixed => require $sourcePath)($canonicalSourcePath);
            $emittedOutput = ob_get_clean();
        } catch (Throwable $exception) {
            while (ob_get_level() > $initialBufferLevel) {
                ob_end_clean();
            }

            throw new LegacyNutritionImportPlanningException(
                'The approved legacy source could not be loaded.',
                previous: $exception,
            );
        }

        if ($emittedOutput === false || $emittedOutput !== '') {
            throw new LegacyNutritionImportPlanningException('The approved legacy source emitted output while loading.');
        }

        if (! is_array($payload) || array_is_list($payload)) {
            throw new LegacyNutritionImportPlanningException('The approved legacy source must return a PHP object array.');
        }

        return new LoadedLegacyNutritionSource(
            artifactPath: $artifactPath,
            rawBytes: $rawBytes,
            payload: $payload,
            checksum: $expectedChecksum,
        );
    }
}
