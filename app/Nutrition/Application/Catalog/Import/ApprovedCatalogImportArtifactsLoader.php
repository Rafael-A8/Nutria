<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\Exceptions\ApprovedCatalogImportValidationException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedApprovedCatalogImportArtifacts;
use App\Nutrition\Application\Catalog\Import\ValueObjects\SourceArtifactChecksum;
use Throwable;

final class ApprovedCatalogImportArtifactsLoader
{
    public const APPROVED_SOURCE_SHA256 = '0d3987cc616b40e878731ecda0127a5b0a065f9557977b5b8f4ec0091d4ecc21';

    public const APPROVED_RESOLUTION_SHA256 = '8eb9db29c044712134c4597220bdb7e61b19f186395c3dcc289cfe31c0054a5d';

    public const APPROVED_APPROVAL_SHA256 = '9207cb1f556f0e5a9216e9ee9f651f446994c05ebf2cf7bd8893d4754d5ac105';

    private const REVIEW_TEMPLATE_PATH = 'resources/catalog-import/review/legacy_config_nutrition_v1/'
        .'resolution-template-b9c1d4ae30c70208bf57bea51e6a6824886e129ecda20afe632ea3f47d28889e.json';

    public function __construct(
        private LegacyNutritionSourceLoader $sourceLoader,
        private LegacyNutritionReviewManifestLoader $manifestLoader,
        private CatalogImportReviewedResolutionLoader $resolutionLoader,
        private CatalogImportApprovalAttestationLoader $approvalLoader,
        private ApprovedCatalogImportApplyPlanLoader $applyPlanLoader,
    ) {}

    /** @param array<string, string> $options */
    public function loadCommandOptions(array $options): LoadedApprovedCatalogImportArtifacts
    {
        try {
            if ($options['expected-source-sha256'] !== self::APPROVED_SOURCE_SHA256) {
                throw new ApprovedCatalogImportValidationException(
                    ApprovedCatalogImportOutcome::SourceDrift,
                    'The expected source checksum is not the formally approved raw-source checksum.',
                );
            }

            $source = $this->sourceLoader->load(
                repositoryRoot: base_path(),
                artifactPath: $options['source'],
                expectedChecksum: new SourceArtifactChecksum('sha256', $options['expected-source-sha256']),
            );
        } catch (Throwable $exception) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::SourceDrift,
                'The approved legacy source bytes or checksum have drifted.',
                $exception,
            );
        }

        try {
            $manifest = $this->manifestLoader->load(
                $options['manifest'],
                $options['expected-manifest-sha256'],
            );
        } catch (Throwable $exception) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ArtifactInvalid,
                'The approved candidate manifest is invalid.',
                $exception,
            );
        }

        try {
            if ($options['expected-resolution-sha256'] !== self::APPROVED_RESOLUTION_SHA256) {
                throw new ApprovedCatalogImportValidationException(
                    ApprovedCatalogImportOutcome::ArtifactInvalid,
                    'The reviewed resolution is not the exact formally approved artifact.',
                );
            }

            $resolution = $this->resolutionLoader->load(
                resolutionPath: $options['resolution'],
                expectedSha256: $options['expected-resolution-sha256'],
                baselinePath: base_path(self::REVIEW_TEMPLATE_PATH),
                manifest: $manifest,
            );
        } catch (ApprovedCatalogImportValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ArtifactInvalid,
                'The approved reviewed resolution is invalid.',
                $exception,
            );
        }

        try {
            if ($options['expected-approval-sha256'] !== self::APPROVED_APPROVAL_SHA256) {
                throw new ApprovedCatalogImportValidationException(
                    ApprovedCatalogImportOutcome::ArtifactInvalid,
                    'The approval is not the exact formally approved artifact.',
                );
            }

            $approval = $this->approvalLoader->load(
                approvalPath: $options['approval'],
                expectedSha256: $options['expected-approval-sha256'],
                manifest: $manifest,
                resolution: $resolution,
            );
        } catch (ApprovedCatalogImportValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ApprovedCatalogImportValidationException(
                ApprovedCatalogImportOutcome::ArtifactInvalid,
                'The approved attestation is invalid.',
                $exception,
            );
        }

        $applyPlan = $this->applyPlanLoader->load(
            path: $options['apply-plan'],
            expectedSha256: $options['expected-apply-plan-sha256'],
            manifest: $manifest,
            resolution: $resolution,
            approval: $approval,
        );

        return new LoadedApprovedCatalogImportArtifacts(
            source: $source,
            manifest: $manifest,
            resolution: $resolution,
            approval: $approval,
            applyPlan: $applyPlan,
            applyPlanChecksum: $options['expected-apply-plan-sha256'],
        );
    }
}
