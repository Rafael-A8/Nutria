<?php

use App\Nutrition\Application\Catalog\Import\ApprovedLegacyNutritionReviewManifestValidator;
use App\Nutrition\Application\Catalog\Import\CanonicalCatalogImportJson;
use App\Nutrition\Application\Catalog\Import\CatalogImportResolutionDocumentValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewedResolutionLoader;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewedResolutionValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewEligibilityValidator;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionReviewManifestLoader;

const MANIFEST_SHA256_M244B = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';
const RESOLUTION_TEMPLATE_SHA256_M244B = 'b9c1d4ae30c70208bf57bea51e6a6824886e129ecda20afe632ea3f47d28889e';

function projectRootM244b(): string
{
    return dirname(__DIR__, 2);
}

function manifestPathM244b(): string
{
    return projectRootM244b().'/resources/catalog-import/approved/legacy_config_nutrition_v1/'
        .'candidate-manifest-'.MANIFEST_SHA256_M244B.'.json';
}

function resolutionTemplatePathM244b(): string
{
    return projectRootM244b().'/resources/catalog-import/review/legacy_config_nutrition_v1/'
        .'resolution-template-'.RESOLUTION_TEMPLATE_SHA256_M244B.'.json';
}

/** @return array<string, mixed> */
function reviewedResolutionDocumentM244b(
    ?string $selectedKey = 'abacate',
    array $decisionOverrides = [],
    array $aliasDecisionOverrides = [],
): array {
    $document = json_decode(
        file_get_contents(resolutionTemplatePathM244b()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if ($selectedKey === null) {
        return $document;
    }

    foreach ($document['review_entries'] as &$entry) {
        if ($entry['source_record_key'] !== $selectedKey) {
            continue;
        }

        $entry = array_replace($entry, [
            'catalog_classification' => 'synthetic_test_food',
            'editorial_notes' => 'Synthetic fixture decision only.',
            'existing_reference_public_id' => null,
            'is_generic' => true,
            'owner_user_id' => null,
            'owner_user_id_decision' => 'explicit_null',
            'preparation_decision' => ['preparation_key' => null, 'status' => 'explicit_null'],
            'reference_target' => 'new_reference',
            'reference_visibility' => 'global',
            'selected_for_apply' => true,
            'stable_key' => 'synthetic-'.$selectedKey,
            'version_locale' => 'pt-BR',
        ], $decisionOverrides);

        foreach ($entry['alias_decisions'] as &$aliasDecision) {
            $aliasDecision = array_replace($aliasDecision, [
                'alias_kind' => 'common',
                'status' => 'include',
            ], $aliasDecisionOverrides);
        }
        unset($aliasDecision);
    }
    unset($entry);

    $document['summary'] = [
        'approved_apply_plan_records' => 0,
        'resolved_candidate_identities' => 1,
        'review_entries' => 106,
        'selected_candidates' => 1,
        'unresolved_candidate_identities' => 105,
    ];

    return $document;
}

/** @param array<string, mixed> $document */
function canonicalDocumentBytesM244b(array $document): string
{
    return CanonicalCatalogImportJson::serializeSemanticGraph($document);
}

/** @return array<string, mixed> */
function approvalDocumentM244b(string $resolutionSha256, array $overrides = []): array
{
    return array_replace([
        'approved_at' => '2026-08-02T12:34:56.123456Z',
        'approval_reason' => 'Synthetic approval used exclusively by completion verification.',
        'candidate_manifest_sha256' => MANIFEST_SHA256_M244B,
        'logical_artifact_id' => 'legacy_config_nutrition_v1',
        'reviewed_resolution_sha256' => $resolutionSha256,
        'reviewer_reference' => 'completion-verification:m244b',
        'schema' => 'nutria.catalog-import-resolution-approval/1',
    ], $overrides);
}

function temporaryDocumentM244b(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'm244b-');

    if ($path === false) {
        throw new RuntimeException('Could not allocate a synthetic M2.4.4b fixture.');
    }

    file_put_contents($path, $bytes);

    return $path;
}

function reviewedResolutionLoaderM244b(): CatalogImportReviewedResolutionLoader
{
    return new CatalogImportReviewedResolutionLoader(
        new CatalogImportReviewedResolutionValidator(
            new CatalogImportResolutionDocumentValidator,
            new CatalogImportReviewEligibilityValidator,
        ),
    );
}

function approvedManifestM244b(): mixed
{
    return (new LegacyNutritionReviewManifestLoader(
        new ApprovedLegacyNutritionReviewManifestValidator,
    ))->load(manifestPathM244b(), MANIFEST_SHA256_M244B);
}
