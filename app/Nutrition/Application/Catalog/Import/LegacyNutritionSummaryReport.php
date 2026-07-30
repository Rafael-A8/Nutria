<?php

namespace App\Nutrition\Application\Catalog\Import;

use App\Nutrition\Application\Catalog\Import\Exceptions\LegacyNutritionImportPlanningException;
use App\Nutrition\Application\Catalog\Import\ValueObjects\LegacyNutritionPlanningResult;
use JsonException;

final class LegacyNutritionSummaryReport
{
    public function render(LegacyNutritionPlanningResult $result): string
    {
        try {
            return json_encode(
                [
                    ...$result->summary,
                    'manifest_checksum' => [
                        'algorithm' => $result->manifestChecksum->algorithm,
                        'digest' => $result->manifestChecksum->digest,
                    ],
                    'output_status' => 'written',
                ],
                JSON_THROW_ON_ERROR
                    | JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ).PHP_EOL;
        } catch (JsonException $exception) {
            throw new LegacyNutritionImportPlanningException(
                'The legacy nutrition summary report could not be encoded.',
                previous: $exception,
            );
        }
    }
}
