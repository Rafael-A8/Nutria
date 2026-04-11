<?php

namespace App\Services;

use Illuminate\Support\Str;

class MealAmbiguityService
{
    private const HIGH_IMPACT_COOKING_FAT_GRAMS = 20;

    /** @var list<string> */
    private const PREPARATION_KEYWORDS = [
        'no preparo',
        'na panela',
        'na frigideira',
        'na assadeira',
        'fiz com',
        'feito com',
        'fritei',
        'grelhei com',
        'assado com',
        'refoguei',
        'untei',
        'para preparar',
        'usada no preparo',
        'usado no preparo',
    ];

    /** @var list<string> */
    private const EXPLICIT_CONSUMPTION_KEYWORDS = [
        'molho',
        'por cima',
        'cobertura',
        'creme',
        'maionese',
        'requeijao',
        'requeijão',
        'caldo',
        'recheio',
        'servido junto',
        'misturado no prato',
    ];

    /** @var list<string> */
    private const VAGUE_QUANTITY_KEYWORDS = [
        'um pouco',
        'pouco',
        'mais ou menos',
        'algum',
        'alguns',
        'umas',
        'aproximadamente',
    ];

    /**
     * @return array{requires_clarification: bool, clarification_question: string|null, reason: string|null, treat_as_preparation_only: bool, is_low_confidence: bool}
     */
    public function assess(
        string $description,
        ?int $quantityGrams = null,
        ?string $quantityText = null,
        ?string $context = null,
        bool $isCookingFat = false,
        bool $hasReference = false,
        bool $hasHistory = false,
    ): array {
        $combinedText = trim(implode(' ', array_filter([$description, $quantityText, $context])));
        $looksLikePreparation = $isCookingFat
            && $this->looksLikePreparationContext($combinedText)
            && ! $this->looksExplicitlyConsumedContext($combinedText);

        if ($looksLikePreparation && $quantityGrams === null) {
            return [
                'requires_clarification' => true,
                'clarification_question' => "Quanto de {$description} foi usado no preparo? Se lembrar, me diga em colheres ou gramas.",
                'reason' => 'Ingrediente gorduroso usado no preparo sem quantidade definida.',
                'treat_as_preparation_only' => true,
                'is_low_confidence' => false,
            ];
        }

        if ($looksLikePreparation && $quantityGrams !== null && $quantityGrams >= self::HIGH_IMPACT_COOKING_FAT_GRAMS) {
            return [
                'requires_clarification' => true,
                'clarification_question' => "Esse {$description} ficou só no preparo ou você consumiu ele como molho/caldo no prato?",
                'reason' => 'Ingrediente de preparo com impacto calórico relevante.',
                'treat_as_preparation_only' => true,
                'is_low_confidence' => false,
            ];
        }

        if (! $hasReference && ! $hasHistory && $quantityGrams === null && $this->containsVagueQuantity($combinedText)) {
            return [
                'requires_clarification' => true,
                'clarification_question' => "Qual foi aproximadamente a porção de {$description}? Se for industrializado, me diga também a marca.",
                'reason' => 'Porção vaga sem referência determinística.',
                'treat_as_preparation_only' => false,
                'is_low_confidence' => false,
            ];
        }

        if (! $hasReference && ! $hasHistory) {
            return [
                'requires_clarification' => false,
                'clarification_question' => null,
                'reason' => null,
                'treat_as_preparation_only' => false,
                'is_low_confidence' => true,
            ];
        }

        return [
            'requires_clarification' => false,
            'clarification_question' => null,
            'reason' => null,
            'treat_as_preparation_only' => $looksLikePreparation,
            'is_low_confidence' => false,
        ];
    }

    public function looksLikePreparationContext(?string $text): bool
    {
        $normalizedText = $this->normalize($text);

        return $normalizedText !== ''
            && collect(self::PREPARATION_KEYWORDS)->contains(fn (string $keyword) => str_contains($normalizedText, $this->normalize($keyword)));
    }

    public function looksExplicitlyConsumedContext(?string $text): bool
    {
        $normalizedText = $this->normalize($text);

        return $normalizedText !== ''
            && collect(self::EXPLICIT_CONSUMPTION_KEYWORDS)->contains(fn (string $keyword) => str_contains($normalizedText, $this->normalize($keyword)));
    }

    private function containsVagueQuantity(string $text): bool
    {
        $normalizedText = $this->normalize($text);

        return $normalizedText !== ''
            && collect(self::VAGUE_QUANTITY_KEYWORDS)->contains(fn (string $keyword) => str_contains($normalizedText, $this->normalize($keyword)));
    }

    private function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return Str::of(Str::ascii($text))
            ->lower()
            ->squish()
            ->toString();
    }
}
