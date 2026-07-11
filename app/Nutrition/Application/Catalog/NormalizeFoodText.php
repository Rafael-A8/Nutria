<?php

namespace App\Nutrition\Application\Catalog;

use App\Nutrition\Domain\Catalog\ValueObjects\NormalizedFoodText;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Normalizer;
use RuntimeException;

final class NormalizeFoodText
{
    public function normalize(string $foodText): NormalizedFoodText
    {
        if (! mb_check_encoding($foodText, 'UTF-8')) {
            throw new InvalidArgumentException('Food text must contain valid UTF-8.');
        }

        $normalizedText = trim($foodText);

        if (class_exists(Normalizer::class)) {
            $unicodeNormalizedText = Normalizer::normalize($normalizedText, Normalizer::FORM_KC);

            if ($unicodeNormalizedText === false) {
                throw new InvalidArgumentException('Food text could not be Unicode-normalized.');
            }

            $normalizedText = $unicodeNormalizedText;
        }

        $normalizedText = mb_strtolower($normalizedText, 'UTF-8');
        $normalizedText = Str::ascii($normalizedText);
        $normalizedText = preg_replace('/[\p{P}\p{S}_]+/u', ' ', $normalizedText);

        if ($normalizedText === null) {
            throw new RuntimeException('Food punctuation normalization failed.');
        }

        $normalizedText = preg_replace('/\s+/u', ' ', trim($normalizedText));

        if ($normalizedText === null) {
            throw new RuntimeException('Food whitespace normalization failed.');
        }

        return new NormalizedFoodText(
            original: $foodText,
            value: $normalizedText,
        );
    }
}
