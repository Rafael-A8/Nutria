<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportPlanningException;

final class AtomicCatalogImportOutputWriter
{
    public function write(
        string $manifestPath,
        string $reportPath,
        string $manifestBytes,
        string $reportBytes,
    ): void {
        $this->assertDestination($manifestPath, 'manifest');

        if ($reportPath !== '-') {
            $this->assertDestination($reportPath, 'report');

            if ($this->normalizedPath($manifestPath) === $this->normalizedPath($reportPath)) {
                throw new LegacyNutritionImportPlanningException('Manifest and report destinations must be different.');
            }
        }

        $manifestTemporaryPath = null;
        $reportTemporaryPath = null;
        $manifestPublished = false;

        try {
            $manifestTemporaryPath = $this->writeTemporaryFile($manifestPath, $manifestBytes);

            if ($reportPath !== '-') {
                $reportTemporaryPath = $this->writeTemporaryFile($reportPath, $reportBytes);
            }

            $this->publish($manifestTemporaryPath, $manifestPath, 'manifest');
            $manifestTemporaryPath = null;
            $manifestPublished = true;

            if ($reportPath !== '-' && $reportTemporaryPath !== null) {
                $this->publish($reportTemporaryPath, $reportPath, 'report');
                $reportTemporaryPath = null;
            }
        } catch (\Throwable $exception) {
            $this->removeIfPresent($manifestTemporaryPath);
            $this->removeIfPresent($reportTemporaryPath);

            if ($manifestPublished && $reportPath !== '-') {
                $this->removeIfPresent($manifestPath);
            }

            if ($exception instanceof LegacyNutritionImportPlanningException) {
                throw $exception;
            }

            throw new LegacyNutritionImportPlanningException(
                'The legacy import outputs could not be written atomically.',
                previous: $exception,
            );
        }
    }

    private function assertDestination(string $path, string $kind): void
    {
        if (trim($path) === '' || $path === '-') {
            throw new LegacyNutritionImportPlanningException("An explicit {$kind} output path is required.");
        }

        if (file_exists($path) || is_link($path)) {
            throw new LegacyNutritionImportPlanningException("The {$kind} destination already exists.");
        }

        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new LegacyNutritionImportPlanningException("The {$kind} destination directory is not writable.");
        }
    }

    private function writeTemporaryFile(string $destination, string $bytes): string
    {
        $temporaryPath = tempnam(dirname($destination), '.nutria-catalog-import-');

        if ($temporaryPath === false) {
            throw new LegacyNutritionImportPlanningException('A temporary output file could not be allocated.');
        }

        $handle = @fopen($temporaryPath, 'wb');

        if ($handle === false) {
            $this->removeIfPresent($temporaryPath);

            throw new LegacyNutritionImportPlanningException('A temporary output file could not be opened.');
        }

        try {
            $remainingBytes = $bytes;

            while ($remainingBytes !== '') {
                $writtenBytes = fwrite($handle, $remainingBytes);

                if ($writtenBytes === false || $writtenBytes === 0) {
                    throw new LegacyNutritionImportPlanningException('A temporary output file could not be written completely.');
                }

                $remainingBytes = substr($remainingBytes, $writtenBytes);
            }

            if (! fflush($handle)) {
                throw new LegacyNutritionImportPlanningException('A temporary output file could not be flushed.');
            }

            if (function_exists('fsync') && ! fsync($handle)) {
                throw new LegacyNutritionImportPlanningException('A temporary output file could not be synchronized.');
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            $this->removeIfPresent($temporaryPath);

            throw $exception;
        }

        fclose($handle);

        return $temporaryPath;
    }

    private function publish(string $temporaryPath, string $destination, string $kind): void
    {
        if (file_exists($destination) || is_link($destination)) {
            throw new LegacyNutritionImportPlanningException("The {$kind} destination appeared before publication.");
        }

        if (! @rename($temporaryPath, $destination)) {
            throw new LegacyNutritionImportPlanningException("The {$kind} output could not be published atomically.");
        }
    }

    private function removeIfPresent(?string $path): void
    {
        if ($path !== null && (is_file($path) || is_link($path))) {
            @unlink($path);
        }
    }

    private function normalizedPath(string $path): string
    {
        $directory = realpath(dirname($path));

        return ($directory === false ? dirname($path) : $directory).DIRECTORY_SEPARATOR.basename($path);
    }
}
