<?php

namespace App\Nutrition\Infrastructure\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\CatalogImportPreflightReport;
use App\Nutrition\Application\Catalog\Import\CatalogImportResolutionDocumentValidator;
use App\Nutrition\Application\Catalog\Import\CatalogImportReviewTemplateGenerator;
use App\Nutrition\Application\Catalog\Import\LegacyNutritionReviewManifestLoader;
use App\Nutrition\Application\Catalog\Import\ValueObjects\CatalogImportReviewPreparationResult;
use App\Nutrition\Application\Catalog\NormalizeFoodText;

final class LegacyCatalogImportReviewPreparer
{
    public function __construct(
        private LegacyNutritionReviewManifestLoader $manifestLoader,
        private NormalizeFoodText $normalizeFoodText,
        private ReadOnlyCatalogImportPreflight $preflight,
        private CatalogImportReviewTemplateGenerator $templateGenerator,
        private CatalogImportPreflightReport $preflightReport,
        private CatalogImportResolutionDocumentValidator $resolutionValidator,
    ) {}

    public function prepare(
        string $manifestPath,
        string $expectedManifestSha256,
    ): CatalogImportReviewPreparationResult {
        $manifest = $this->manifestLoader->load($manifestPath, $expectedManifestSha256);
        $preflight = $this->preflight->inspect(
            array_map(
                fn (array $record): array => [
                    'existing_reference_public_id' => null,
                    'is_generic' => null,
                    'normalized_aliases' => array_column($record['aliases'], 'normalized_alias'),
                    'normalized_canonical_name' => $this->normalizeFoodText
                        ->normalize($record['source_record_key'])
                        ->value,
                    'owner_user_id' => null,
                    'reference_target' => 'unresolved',
                    'reference_visibility' => null,
                    'source_record_key' => $record['source_record_key'],
                    'stable_key' => null,
                ],
                $manifest->records(),
            ),
        );
        $result = $this->templateGenerator->generate($manifest, $preflight, $this->preflightReport);
        $this->resolutionValidator->validate($result->resolutionDocument, $manifest->checksum);

        return $result;
    }
}
