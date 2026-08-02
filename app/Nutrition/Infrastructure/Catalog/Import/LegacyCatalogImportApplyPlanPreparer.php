<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\CatalogImportApplyPlanBuilder;
use App\Nutrition\Application\Catalog\Import\CatalogImportApprovalAttestationLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewedResolutionLoader;
use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionReviewManifestLoader;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanResult;
use App\Nutrition\Application\Catalog\NormalizeFoodText;

final class LegacyCatalogImportApplyPlanPreparer
{
    public function __construct(
        private LegacyNutritionReviewManifestLoader $manifestLoader,
        private CatalogImportReviewedResolutionLoader $resolutionLoader,
        private CatalogImportApprovalAttestationLoader $approvalLoader,
        private NormalizeFoodText $normalizeFoodText,
        private ReadOnlyCatalogImportPreflight $committedPreflight,
        private ReadOnlyCatalogImportApplyPlanPreflight $applyPlanPreflight,
        private CatalogImportApplyPlanBuilder $builder,
    ) {}

    public function prepare(
        string $manifestPath,
        string $expectedManifestSha256,
        string $resolutionPath,
        string $expectedResolutionSha256,
        string $approvalPath,
        string $expectedApprovalSha256,
    ): CatalogImportApplyPlanResult {
        $manifest = $this->manifestLoader->load($manifestPath, $expectedManifestSha256);
        $resolution = $this->resolutionLoader->load(
            resolutionPath: $resolutionPath,
            expectedSha256: $expectedResolutionSha256,
            baselinePath: base_path(
                'resources/catalog-import/review/legacy_config_nutrition_v1/'
                .'resolution-template-b9c1d4ae30c70208bf57bea51e6a6824886e129ecda20afe632ea3f47d28889e.json',
            ),
            manifest: $manifest,
        );
        $approval = $this->approvalLoader->load(
            approvalPath: $approvalPath,
            expectedSha256: $expectedApprovalSha256,
            manifest: $manifest,
            resolution: $resolution,
        );

        if ($resolution->selectedEntries === []) {
            throw new LegacyNutritionApplyPlanException(
                'no_candidates_selected',
                'no_candidates_selected: the reviewed resolution selected no candidates.',
            );
        }

        $normalizedCanonicalNames = [];
        $preflightCandidates = [];

        foreach ($resolution->selectedEntries as $entry) {
            $normalizedCanonicalName = $this->normalizeFoodText->normalize($entry['canonical_name'])->value;
            $normalizedCanonicalNames[$entry['source_record_key']] = $normalizedCanonicalName;
            $preflightCandidates[] = [
                'existing_reference_public_id' => $entry['existing_reference_public_id'],
                'is_generic' => $entry['is_generic'],
                'normalized_aliases' => array_column($entry['alias_decisions'], 'normalized_alias'),
                'normalized_canonical_name' => $normalizedCanonicalName,
                'owner_user_id' => $entry['owner_user_id'],
                'owner_user_id_decision' => $entry['owner_user_id_decision'],
                'reference_target' => $entry['reference_target'],
                'reference_visibility' => $entry['reference_visibility'],
                'source_record_key' => $entry['source_record_key'],
                'stable_key' => $entry['stable_key'],
            ];
        }

        $committedPreflight = $this->committedPreflight->inspect($preflightCandidates);
        $snapshot = $this->applyPlanPreflight->inspect($resolution->selectedEntries, $committedPreflight);

        return $this->builder->build(
            manifest: $manifest,
            resolution: $resolution,
            approval: $approval,
            snapshot: $snapshot,
            normalizedCanonicalNames: $normalizedCanonicalNames,
        );
    }
}
