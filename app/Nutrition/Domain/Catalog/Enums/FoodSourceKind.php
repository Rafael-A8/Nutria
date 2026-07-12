<?php

namespace App\Nutrition\Domain\Catalog\Enums;

enum FoodSourceKind: string
{
    case CuratedDataset = 'curated_dataset';
    case ScientificPublication = 'scientific_publication';
    case ManufacturerLabel = 'manufacturer_label';
    case UserProductLabel = 'user_product_label';
    case LegacyConfig = 'legacy_config';
    case AppGeneratedEstimate = 'app_generated_estimate';
}
