<?php

namespace App\Console\Commands;

use App\Nutrition\Application\Catalog\Import\AtomicCatalogImportOutputWriter;
use App\Nutrition\Infrastructure\Catalog\Import\LegacyCatalogImportApplyPlanPreparer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog:build-legacy-import-apply-plan
    {--manifest= : Exact approved M2.4.3 candidate-manifest path}
    {--expected-manifest-sha256= : Expected SHA-256 of the exact candidate-manifest bytes}
    {--resolution= : Canonical reviewed editorial resolution path}
    {--expected-resolution-sha256= : Expected SHA-256 of the exact reviewed-resolution bytes}
    {--approval= : Canonical detached editorial approval-attestation path}
    {--expected-approval-sha256= : Expected SHA-256 of the exact approval-attestation bytes}
    {--apply-plan-output= : New canonical deterministic apply-plan path}
    {--report= : New operational report path, or - for stdout}')]
#[Description('Validate reviewed editorial decisions and build a deterministic read-only catalog apply plan')]
final class BuildLegacyCatalogImportApplyPlanCommand extends Command
{
    public function __construct(
        private LegacyCatalogImportApplyPlanPreparer $preparer,
        private AtomicCatalogImportOutputWriter $outputWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $manifestPath = $this->requiredOption('manifest');
            $expectedManifestSha256 = $this->requiredOption('expected-manifest-sha256');
            $resolutionPath = $this->requiredOption('resolution');
            $expectedResolutionSha256 = $this->requiredOption('expected-resolution-sha256');
            $approvalPath = $this->requiredOption('approval');
            $expectedApprovalSha256 = $this->requiredOption('expected-approval-sha256');
            $applyPlanOutput = $this->requiredOption('apply-plan-output');
            $reportOutput = $this->requiredOption('report');
            $result = $this->preparer->prepare(
                manifestPath: $manifestPath,
                expectedManifestSha256: $expectedManifestSha256,
                resolutionPath: $resolutionPath,
                expectedResolutionSha256: $expectedResolutionSha256,
                approvalPath: $approvalPath,
                expectedApprovalSha256: $expectedApprovalSha256,
            );

            $this->outputWriter->write(
                manifestPath: $applyPlanOutput,
                reportPath: $reportOutput,
                manifestBytes: $result->canonicalPlanBytes,
                reportBytes: $result->reportBytes,
            );

            if ($reportOutput === '-') {
                $this->output->write($result->reportBytes);

                return self::SUCCESS;
            }

            $this->components->info("Deterministic apply plan written with SHA-256 {$result->checksum->digest}.");

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
