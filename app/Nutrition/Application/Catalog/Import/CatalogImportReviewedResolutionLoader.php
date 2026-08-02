<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionApplyPlanException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LoadedCatalogImportReviewedResolution;
use JsonException;

final class CatalogImportReviewedResolutionLoader
{
    public function __construct(private CatalogImportReviewedResolutionValidator $validator) {}

    public function load(
        string $resolutionPath,
        string $expectedSha256,
        string $baselinePath,
        LegacyNutritionReviewManifest $manifest,
    ): LoadedCatalogImportReviewedResolution {
        $bytes = $this->readExactFile($resolutionPath, 'reviewed resolution');
        $this->assertExpectedChecksum($bytes, $expectedSha256, 'reviewed resolution');
        $document = $this->decodeObject($bytes, 'reviewed resolution');

        try {
            $canonicalBytes = CanonicalCatalogImportJson::serializeSemanticGraph($document);
        } catch (\Throwable $exception) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', 'The reviewed resolution violates canonical JSON rules.', $exception);
        }

        if (! hash_equals($bytes, $canonicalBytes)) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', 'The reviewed resolution bytes are not canonical.');
        }

        $baselineBytes = $this->readExactFile($baselinePath, 'tracked unresolved resolution template');

        if (! hash_equals('b9c1d4ae30c70208bf57bea51e6a6824886e129ecda20afe632ea3f47d28889e', hash('sha256', $baselineBytes))) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', 'The tracked unresolved resolution template has drifted.');
        }

        $baseline = $this->decodeObject($baselineBytes, 'tracked unresolved resolution template');
        $validation = $this->validator->validate($document, $baseline, $manifest);

        return new LoadedCatalogImportReviewedResolution(
            document: $document,
            canonicalBytes: $bytes,
            checksum: new CanonicalManifestChecksum('sha256', $expectedSha256),
            selectedEntries: $validation['selected_entries'],
            eligibleEntryCount: $validation['eligible_count'],
        );
    }

    private function readExactFile(string $path, string $label): string
    {
        if (trim($path) === '' || $path === '-' || ! is_file($path) || ! is_readable($path)) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "The {$label} path must identify a readable file.");
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "The {$label} bytes could not be read.");
        }

        return $bytes;
    }

    private function assertExpectedChecksum(string $bytes, string $expectedSha256, string $label): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $expectedSha256) !== 1 || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "The {$label} checksum does not match the exact bytes.");
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $bytes, string $label): array
    {
        try {
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "The {$label} is not valid JSON.", $exception);
        }

        if (! is_array($document) || array_is_list($document)) {
            throw new LegacyNutritionApplyPlanException('invalid_resolution', "The {$label} must be a JSON object.");
        }

        return $document;
    }
}
