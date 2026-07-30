<?php

namespace App\Console\Commands;

use App\Nutrition\Application\Catalog\Import\AtomicCatalogImportOutputWriter;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionCandidateManifestGenerator;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionCommitReader;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionSourceLoader;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionSummaryReport;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog:plan-legacy-import
    {--source= : Repository-relative legacy source path}
    {--expected-source-sha256= : Expected SHA-256 of the exact source bytes}
    {--manifest-output= : New canonical manifest output path}
    {--report= : New summary report path, or - for stdout}')]
#[Description('Generate a deterministic read-only candidate manifest from the approved legacy nutrition source')]
final class PlanLegacyCatalogImportCommand extends Command
{
    public function __construct(
        private LegacyNutritionSourceLoader $sourceLoader,
        private LegacyNutritionCandidateManifestGenerator $manifestGenerator,
        private LegacyNutritionCommitReader $commitReader,
        private LegacyNutritionSummaryReport $summaryReport,
        private AtomicCatalogImportOutputWriter $outputWriter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $sourcePath = $this->requiredOption('source');
            $expectedSourceSha256 = $this->requiredOption('expected-source-sha256');
            $manifestOutput = $this->requiredOption('manifest-output');
            $reportOutput = $this->requiredOption('report');

            if (preg_match('/^[0-9a-f]{64}$/D', $expectedSourceSha256) !== 1) {
                throw new \InvalidArgumentException('The expected source SHA-256 must be a canonical lowercase digest.');
            }

            $source = $this->sourceLoader->load(
                repositoryRoot: base_path(),
                artifactPath: $sourcePath,
                expectedChecksum: new SourceArtifactChecksum('sha256', $expectedSourceSha256),
            );
            $result = $this->manifestGenerator->generate(
                source: $source,
                repositoryCommit: $this->commitReader->resolve(base_path()),
            );
            $reportBytes = $this->summaryReport->render($result);

            $this->outputWriter->write(
                manifestPath: $manifestOutput,
                reportPath: $reportOutput,
                manifestBytes: $result->canonicalManifestBytes,
                reportBytes: $reportBytes,
            );

            if ($reportOutput === '-') {
                $this->output->write($reportBytes);

                return self::SUCCESS;
            }

            $this->components->info(
                "Candidate manifest written with SHA-256 {$result->manifestChecksum->digest}.",
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
