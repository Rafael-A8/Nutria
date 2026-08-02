<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Enums\ApprovedCatalogImportOutcome;
use App\Nutrition\Application\Catalog\Import\Exceptions\ApprovedCatalogImportValidationException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportApplyPlanSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportApproval;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;
use JsonException;
use Throwable;

final class ApprovedCatalogImportApplyPlanLoader
{
    public const APPROVED_SHA256 = '3bb9c7348f6f7386b1cd7667af7cd26527dcf429481410d00684d8dae48a0afb';

    public const APPROVED_PREFLIGHT_FINGERPRINT = '9acc2620ad35c63d051f96333efdbcc5a8bdd8bde43fe085e4709626024ca8b6';

    private const TOP_LEVEL_KEYS = [
        'approval_attestation_sha256',
        'candidate_manifest_sha256',
        'catalog_preflight',
        'logical_artifact_id',
        'reviewed_resolution_sha256',
        'schema',
        'selected_candidate_count',
        'selected_candidate_plans',
        'source_plan',
        'source_sha256',
    ];

    /**
     * @return array<string, mixed>
     */
    public function load(
        string $path,
        string $expectedSha256,
        LegacyNutritionReviewManifest $manifest,
        LoadedCatalogImportReviewedResolution $resolution,
        LoadedCatalogImportApproval $approval,
    ): array {
        if (trim($path) === '' || $path === '-' || ! is_file($path) || ! is_readable($path)) {
            $this->invalid('The approved apply-plan path must identify a readable file.');
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            $this->invalid('The approved apply-plan bytes could not be read.');
        }

        if (
            preg_match('/^[0-9a-f]{64}$/D', $expectedSha256) !== 1
            || ! hash_equals($expectedSha256, hash('sha256', $bytes))
        ) {
            $this->invalid('The apply-plan checksum does not match the exact input bytes.');
        }

        try {
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->invalid('The approved apply plan is not valid JSON.', $exception);
        }

        if (! is_array($document) || array_is_list($document)) {
            $this->invalid('The approved apply plan must be a JSON object.');
        }

        try {
            $canonicalBytes = CanonicalCatalogImportJson::serializeSemanticGraph($document);
        } catch (Throwable $exception) {
            $this->invalid('The approved apply plan violates canonical JSON rules.', $exception);
        }

        if (! hash_equals($bytes, $canonicalBytes)) {
            $this->invalid('The approved apply-plan bytes are not canonical.');
        }

        $keys = array_keys($document);
        sort($keys);
        $expectedKeys = self::TOP_LEVEL_KEYS;
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            $this->invalid('The approved apply plan contains unknown or missing top-level keys.');
        }

        if (($document['schema'] ?? null) !== CatalogImportApplyPlanSchema::IDENTIFIER) {
            $this->invalid('The approved apply-plan schema is unsupported.');
        }

        if (
            ($document['candidate_manifest_sha256'] ?? null) !== $manifest->checksum->digest
            || ($document['reviewed_resolution_sha256'] ?? null) !== $resolution->checksum->digest
            || ($document['approval_attestation_sha256'] ?? null) !== $approval->checksum->digest
            || ($document['source_sha256'] ?? null) !== $manifest->sourceChecksum()
            || ($document['logical_artifact_id'] ?? null) !== LegacyCatalogArtifactDescriptor::ARTIFACT_ID
        ) {
            $this->invalid('The approved apply plan is bound to different import documents.');
        }

        if (
            ($document['selected_candidate_count'] ?? null) !== 3
            || ($document['catalog_preflight']['fingerprint'] ?? null) !== self::APPROVED_PREFLIGHT_FINGERPRINT
            || ($document['catalog_preflight']['catalog_counts'] ?? null) !== [
                'aliases' => 0,
                'reference_version_sources' => 0,
                'reference_versions' => 0,
                'references' => 0,
                'sources' => 0,
            ]
        ) {
            $this->invalid('The approved apply-plan selection or initial catalog snapshot has drifted.');
        }

        $this->validateFrozenGraph($document);

        if (! hash_equals(self::APPROVED_SHA256, $expectedSha256)) {
            $this->invalid('The validated apply plan is not the exact formally approved artifact.');
        }

        return $document;
    }

    /** @param array<string, mixed> $document */
    private function validateFrozenGraph(array $document): void
    {
        $source = $document['source_plan'] ?? null;

        if (
            ! is_array($source)
            || ($source['action'] ?? null) !== 'create'
            || ($source['outcome'] ?? null) !== 'planned'
            || ($source['semantic_entity']['public_id'] ?? null) !== CatalogImportDeterministicIdentity::sourcePublicId()
            || ($source['semantic_entity']['artifact_id'] ?? null) !== null
            || ($source['semantic_entity']['metadata']['artifact_id'] ?? null) !== LegacyCatalogArtifactDescriptor::ARTIFACT_ID
            || ($source['lifecycle_operation_template']['operation'] ?? null) !== 'create_source'
        ) {
            $this->invalid('The approved source plan is malformed.');
        }

        $plans = $document['selected_candidate_plans'] ?? null;
        $expectedFingerprints = [
            'creme de leite' => '7ceab6ca39d30805fda6b7d82406f97376fbd810b8ece2636e94e87fb583b2d1',
            'doce de leite' => 'cd6351ecdeb28f9332b042b4a58477c2c05b26298d48b69fc1b5a110789cf14b',
            'leite condensado' => '7caff5c6fb1ac7ba1cdf887e367261f26a76582d0b67c55938805b1fac1598d9',
        ];

        if (! is_array($plans) || ! array_is_list($plans) || count($plans) !== 3) {
            $this->invalid('The approved apply plan must contain exactly three selected graphs.');
        }

        foreach ($plans as $index => $plan) {
            $sourceRecordKey = array_keys($expectedFingerprints)[$index];

            if (
                ! is_array($plan)
                || ($plan['source_record_key'] ?? null) !== $sourceRecordKey
                || ($plan['graph_fingerprint'] ?? null) !== $expectedFingerprints[$sourceRecordKey]
                || ($plan['graph_outcome'] ?? null) !== 'planned'
                || ($plan['reference_plan']['action'] ?? null) !== 'create'
                || ($plan['version_plan']['action'] ?? null) !== 'create'
                || ($plan['source_link_plan']['action'] ?? null) !== 'create'
            ) {
                $this->invalid("The approved graph plan for {$sourceRecordKey} is malformed.");
            }

            $persistedAliases = array_values(array_filter(
                $plan['alias_plans'] ?? [],
                fn (mixed $alias): bool => is_array($alias) && ($alias['action'] ?? null) !== 'excluded',
            ));

            if (count($persistedAliases) !== 1 || ($persistedAliases[0]['action'] ?? null) !== 'create_lineage') {
                $this->invalid("The approved alias plan for {$sourceRecordKey} is malformed.");
            }

            if (count($plan['lifecycle_operation_templates'] ?? []) !== 3) {
                $this->invalid("The approved lifecycle templates for {$sourceRecordKey} are malformed.");
            }
        }
    }

    private function invalid(string $message, ?Throwable $previous = null): never
    {
        throw new ApprovedCatalogImportValidationException(
            ApprovedCatalogImportOutcome::ArtifactInvalid,
            $message,
            $previous,
        );
    }
}
