<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportResolutionApprovalSchema;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyCatalogArtifactDescriptor;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportApproval;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class CatalogImportApprovalAttestationLoader
{
    private const KEYS = [
        'approved_at',
        'approval_reason',
        'candidate_manifest_sha256',
        'logical_artifact_id',
        'reviewed_resolution_sha256',
        'reviewer_reference',
        'schema',
    ];

    public function load(
        string $approvalPath,
        string $expectedSha256,
        LegacyNutritionReviewManifest $manifest,
        LoadedCatalogImportReviewedResolution $resolution,
    ): LoadedCatalogImportApproval {
        if (trim($approvalPath) === '' || $approvalPath === '-' || ! is_file($approvalPath) || ! is_readable($approvalPath)) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation path must identify a readable file.');
        }

        $bytes = @file_get_contents($approvalPath);

        if ($bytes === false) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation bytes could not be read.');
        }

        if (preg_match('/^[0-9a-f]{64}$/D', $expectedSha256) !== 1 || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation checksum does not match the exact bytes.');
        }

        try {
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation is not valid JSON.', $exception);
        }

        if (! is_array($document) || array_is_list($document)) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation must be a JSON object.');
        }

        $keys = array_keys($document);
        $expectedKeys = self::KEYS;
        sort($keys);
        sort($expectedKeys);

        if ($keys !== $expectedKeys) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation contains unknown or missing keys.');
        }

        if (($document['schema'] ?? null) !== CatalogImportResolutionApprovalSchema::IDENTIFIER) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation schema is unsupported.');
        }

        if (
            ($document['candidate_manifest_sha256'] ?? null) !== $manifest->checksum->digest
            || ($document['reviewed_resolution_sha256'] ?? null) !== $resolution->checksum->digest
            || ($document['logical_artifact_id'] ?? null) !== LegacyCatalogArtifactDescriptor::ARTIFACT_ID
        ) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation is bound to different import documents.');
        }

        foreach (['reviewer_reference', 'approval_reason'] as $field) {
            $value = $document[$field] ?? null;

            if (! is_string($value) || trim($value) === '' || trim($value) !== $value || ! mb_check_encoding($value, 'UTF-8')) {
                throw new LegacyNutritionApplyPlanException('invalid_approval', "The approval attestation {$field} must be explicit nonblank text.");
            }
        }

        $this->assertExplicitUtcMicroseconds($document['approved_at'] ?? null);

        try {
            $canonicalBytes = CanonicalCatalogImportJson::serializeSemanticGraph($document);
        } catch (\Throwable $exception) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation violates canonical JSON rules.', $exception);
        }

        if (! hash_equals($bytes, $canonicalBytes)) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval attestation bytes are not canonical.');
        }

        return new LoadedCatalogImportApproval(
            document: $document,
            canonicalBytes: $bytes,
            checksum: new CanonicalManifestChecksum('sha256', $expectedSha256),
        );
    }

    private function assertExplicitUtcMicroseconds(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval timestamp must be explicit UTC with six microsecond digits.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new LegacyNutritionApplyPlanException('invalid_approval', 'The approval timestamp is not a valid UTC instant.');
        }
    }
}
