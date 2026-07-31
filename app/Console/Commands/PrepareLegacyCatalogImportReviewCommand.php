<?php

namespace App\Console\Commands;

use App\Nutrition\Application\Catalog\Import\AtomicCatalogImportOutputWriter;
use App\Nutrition\Infrastructure\Catalog\Import\LegacyCatalogImportReviewPreparer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog:prepare-legacy-import-review
    {--manifest= : Exact approved M2.4.3 candidate-manifest path}
    {--expected-manifest-sha256= : Expected SHA-256 of the exact candidate-manifest bytes}
    {--resolution-output= : New deterministic editorial resolution-template path}
    {--preflight-report= : New operational preflight report path, or - for stdout}')]
#[Description('Prepare an unresolved editorial review template and read-only catalog preflight')]
final class PrepareLegacyCatalogImportReviewCommand extends Command
{
    public function __construct(
        private LegacyCatalogImportReviewPreparer $preparer,
        private AtomicCatalogImportOutputWriter $outputWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $manifestPath = $this->requiredOption('manifest');
            $expectedManifestSha256 = $this->requiredOption('expected-manifest-sha256');
            $resolutionOutput = $this->requiredOption('resolution-output');
            $preflightReportOutput = $this->requiredOption('preflight-report');
            $result = $this->preparer->prepare($manifestPath, $expectedManifestSha256);

            $this->outputWriter->write(
                manifestPath: $resolutionOutput,
                reportPath: $preflightReportOutput,
                manifestBytes: $result->canonicalResolutionBytes,
                reportBytes: $result->preflightReportBytes,
            );

            if ($preflightReportOutput === '-') {
                $this->output->write($result->preflightReportBytes);

                return self::SUCCESS;
            }

            $this->components->info(
                "Editorial resolution template written with SHA-256 {$result->resolutionChecksum->digest}.",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("The --{$name} option is required.");
        }

        return $value;
    }
}
