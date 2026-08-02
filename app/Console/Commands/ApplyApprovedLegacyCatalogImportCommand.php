<?php

namespace App\Console\Commands;

use App\Nutrition\Application\Catalog\Import\ApprovedCatalogImportArtifactsLoader;
use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\Exceptions\ApprovedCatalogImportValidationException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportApplyResult;
use App\Nutrition\Application\Catalog\Import\ValueObjects\ApprovedCatalogImportExecutionInput;
use App\Nutrition\Application\Catalog\Persistence\ApplyApprovedLegacyCatalogImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog:apply-approved-legacy-import
    {--source= : Exact approved legacy source path}
    {--expected-source-sha256= : Expected SHA-256 of the raw legacy source bytes}
    {--manifest= : Exact approved candidate-manifest path}
    {--expected-manifest-sha256= : Expected SHA-256 of the candidate-manifest bytes}
    {--resolution= : Exact reviewed editorial resolution path}
    {--expected-resolution-sha256= : Expected SHA-256 of the reviewed-resolution bytes}
    {--approval= : Exact detached approval-attestation path}
    {--expected-approval-sha256= : Expected SHA-256 of the approval-attestation bytes}
    {--apply-plan= : Exact approved catalog apply-plan path}
    {--expected-apply-plan-sha256= : Expected SHA-256 of the approved apply-plan bytes}
    {--actor-id= : Existing persisted execution actor user ID}
    {--actor-reference= : Explicit stable execution actor reference}
    {--reason= : Explicit lifecycle creation reason}
    {--occurred-at= : Exact UTC RFC3339 timestamp with six microseconds}
    {--execute : Explicitly authorize controlled execution}')]
#[Description('Transactionally materialize the exact approved legacy catalog-import graph')]
final class ApplyApprovedLegacyCatalogImportCommand extends Command
{
    private const OPTION_NAMES = [
        'source',
        'expected-source-sha256',
        'manifest',
        'expected-manifest-sha256',
        'resolution',
        'expected-resolution-sha256',
        'approval',
        'expected-approval-sha256',
        'apply-plan',
        'expected-apply-plan-sha256',
        'actor-id',
        'actor-reference',
        'reason',
        'occurred-at',
    ];

    public function __construct(
        private ApprovedCatalogImportArtifactsLoader $artifactsLoader,
        private ApplyApprovedLegacyCatalogImport $applyImport,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $options = [];

            foreach (self::OPTION_NAMES as $name) {
                $options[$name] = $this->option($name);
            }

            $execute = $this->option('execute') === true;
            ApprovedCatalogImportExecutionInput::validateRequiredCommandOptions($options, $execute);
            $artifacts = $this->artifactsLoader->loadCommandOptions($options);
            $input = ApprovedCatalogImportExecutionInput::fromCommandOptions($options, $execute);
            $result = $this->applyImport->execute($artifacts, $input);
        } catch (ApprovedCatalogImportValidationException $exception) {
            $result = new ApprovedCatalogImportApplyResult($exception->outcome, $exception->getMessage());
        } catch (Throwable) {
            $result = new ApprovedCatalogImportApplyResult(
                ApprovedCatalogImportOutcome::ArtifactInvalid,
                'The approved catalog-import inputs could not be validated.',
            );
        }

        $this->line($result->outcome->value);

        if (! $result->successful()) {
            $this->components->error($result->message);
        }

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }
}
