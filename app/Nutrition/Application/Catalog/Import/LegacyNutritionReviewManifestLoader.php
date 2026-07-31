<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportReviewException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CanonicalManifestChecksum;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionReviewManifest;
use JsonException;
use Throwable;

final class LegacyNutritionReviewManifestLoader
{
    public const APPROVED_MANIFEST_SHA256 = '4e5e5c3c505fca1d613ef8c3dee6bd066cd28876a49cd1b47dd543d9b4996ee2';

    public const APPROVED_MANIFEST_BYTE_SIZE = 228137;

    public function __construct(
        private ApprovedLegacyNutritionReviewManifestValidator $manifestValidator,
    ) {}

    public function load(string $manifestPath, string $expectedManifestSha256): LegacyNutritionReviewManifest
    {
        if (
            trim($manifestPath) === ''
            || $manifestPath === '-'
            || ! is_file($manifestPath)
            || ! is_readable($manifestPath)
        ) {
            throw new LegacyNutritionImportReviewException('The candidate manifest path must identify a readable file.');
        }

        if (
            preg_match('/^[0-9a-f]{64}$/D', $expectedManifestSha256) !== 1
            || $expectedManifestSha256 !== self::APPROVED_MANIFEST_SHA256
        ) {
            throw new LegacyNutritionImportReviewException('The expected candidate-manifest SHA-256 is not the approved M2.4.3 checksum.');
        }

        $canonicalBytes = @file_get_contents($manifestPath);

        if ($canonicalBytes === false) {
            throw new LegacyNutritionImportReviewException('The candidate manifest bytes could not be read.');
        }

        if (
            strlen($canonicalBytes) !== self::APPROVED_MANIFEST_BYTE_SIZE
            || ! hash_equals($expectedManifestSha256, hash('sha256', $canonicalBytes))
        ) {
            throw new LegacyNutritionImportReviewException('The candidate manifest checksum or canonical byte size has drifted.');
        }

        try {
            $manifest = json_decode($canonicalBytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LegacyNutritionImportReviewException('The candidate manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new LegacyNutritionImportReviewException('The candidate manifest must be a JSON object.');
        }

        $this->manifestValidator->validate($manifest);

        try {
            $reserialized = CanonicalCatalogImportJson::serializeManifest($manifest);
        } catch (Throwable $exception) {
            throw new LegacyNutritionImportReviewException('The candidate manifest violates canonical JSON rules.', previous: $exception);
        }

        if (! hash_equals($canonicalBytes, $reserialized)) {
            throw new LegacyNutritionImportReviewException('The candidate manifest bytes are not canonical.');
        }

        return new LegacyNutritionReviewManifest(
            manifest: $manifest,
            canonicalBytes: $canonicalBytes,
            checksum: new CanonicalManifestChecksum('sha256', $expectedManifestSha256),
        );
    }
}
