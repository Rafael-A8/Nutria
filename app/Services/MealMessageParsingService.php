<?php

namespace App\Services;

use Illuminate\Support\Str;

class MealMessageParsingService
{
    /** @var list<string> */
    private const COMPOSITE_MEAL_KEYWORDS = [
        'marmita',
        'quentinha',
        'prato feito',
        'pf',
        'prato',
    ];

    /** @var list<string> */
    private const DIRECT_CONSUMPTION_KEYWORDS = [
        'por cima',
        'molho',
        'recheio',
        'cobertura',
        'caldo',
        'misturado no prato',
    ];

    /**
     * @return array{
     *     status: 'parsed'|'clarification_required',
     *     meal_type: string,
     *     next_step: 'estimate_meal'|'ask_for_clarification',
     *     raw_message: string,
     *     items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null}>,
     *     meal_total_quantity_grams: int|null,
     *     is_composite_meal: bool,
     *     user_facing_summary: string,
     *     assistant_response_guide: string,
     *     clarification_question: string|null,
     *     clarification_reason: string|null
     * }
     */
    public function parse(string $message, ?string $mealTypeHint = null): array
    {
        $normalizedMessage = $this->normalize($message);
        $items = $this->detectItems($normalizedMessage);
        $mealType = $mealTypeHint ?? $this->inferMealType($normalizedMessage) ?? 'outro';
        $mealTotalQuantityGrams = $this->detectCompositeMealTotalGrams($normalizedMessage);
        $isCompositeMeal = $mealTotalQuantityGrams !== null || $this->containsCompositeKeyword($normalizedMessage);

        if ($items === []) {
            return [
                'status' => 'clarification_required',
                'meal_type' => $mealType,
                'next_step' => 'ask_for_clarification',
                'raw_message' => $message,
                'items' => [],
                'meal_total_quantity_grams' => $mealTotalQuantityGrams,
                'is_composite_meal' => $isCompositeMeal,
                'user_facing_summary' => 'Antes de estimar, preciso identificar melhor quais alimentos estavam nessa refeição.',
                'assistant_response_guide' => 'Peça ao usuário que liste os alimentos principais da refeição antes de estimar ou registrar.',
                'clarification_question' => 'Quais alimentos tinha nessa refeição? Se lembrar, me diga também as quantidades ou medidas caseiras.',
                'clarification_reason' => 'Não consegui identificar os itens da refeição com segurança.',
            ];
        }

        if ($mealTotalQuantityGrams !== null && count($items) > 1 && $this->itemsLackSpecificQuantities($items)) {
            $itemNames = collect($items)->pluck('description')->implode(', ');

            return [
                'status' => 'clarification_required',
                'meal_type' => $mealType,
                'next_step' => 'ask_for_clarification',
                'raw_message' => $message,
                'items' => $items,
                'meal_total_quantity_grams' => $mealTotalQuantityGrams,
                'is_composite_meal' => true,
                'user_facing_summary' => "Entendi que foi uma refeição composta com peso total de {$mealTotalQuantityGrams}g, mas ainda não sei quanto de cada item entrou na conta.",
                'assistant_response_guide' => 'Explique que o peso informado parece ser do conjunto da refeição e peça a divisão aproximada antes de estimar ou registrar.',
                'clarification_question' => "Essa refeição de {$mealTotalQuantityGrams}g parece ser o peso total do conjunto. Você consegue me dizer aproximadamente quanto tinha de {$itemNames}?",
                'clarification_reason' => 'Peso total informado para refeição composta sem divisão por item.',
            ];
        }

        return [
            'status' => 'parsed',
            'meal_type' => $mealType,
            'next_step' => 'estimate_meal',
            'raw_message' => $message,
            'items' => $items,
            'meal_total_quantity_grams' => $mealTotalQuantityGrams,
            'is_composite_meal' => $isCompositeMeal,
            'user_facing_summary' => $this->buildParsedSummary($items, $mealTotalQuantityGrams),
            'assistant_response_guide' => 'Use o meal_type e os items retornados exatamente como entrada de estimate_meal. Se houver pergunta adicional do usuário, responda depois da estimativa, sem perder essas estruturas.',
            'clarification_question' => null,
            'clarification_reason' => null,
        ];
    }

    /**
     * @return list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null, _offset: int}>
     */
    private function detectItems(string $normalizedMessage): array
    {
        $matches = [];

        foreach ($this->references() as $reference) {
            $aliasMatch = $this->findBestAliasMatch($normalizedMessage, $reference['aliases']);

            if ($aliasMatch === null) {
                continue;
            }

            $quantity = $this->extractQuantity($normalizedMessage, $aliasMatch['normalized_alias'], $reference['is_cooking_fat'] ?? false);
            $description = $this->buildDescription(
                $aliasMatch['display_alias'],
                $aliasMatch['normalized_alias'],
                $normalizedMessage,
                $aliasMatch['offset'],
            );
            $context = $this->detectContext($normalizedMessage, $aliasMatch['offset'], $reference['is_cooking_fat'] ?? false);

            $matches[] = [
                'description' => $description,
                'quantity_grams' => $quantity['quantity_grams'],
                'quantity_text' => $quantity['quantity_text'],
                'context' => $context,
                '_offset' => $aliasMatch['offset'],
            ];
        }

        return collect($matches)
            ->sortBy('_offset')
            ->values()
            ->map(fn (array $item) => collect($item)->except('_offset')->all())
            ->all();
    }

    /**
     * @param  list<string>  $aliases
     * @return array{display_alias: string, normalized_alias: string, offset: int}|null
     */
    private function findBestAliasMatch(string $normalizedMessage, array $aliases): ?array
    {
        $bestMatch = null;

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalize($alias);
            $offset = strpos($normalizedMessage, $normalizedAlias);

            if ($offset === false) {
                continue;
            }

            if ($bestMatch === null || $offset < $bestMatch['offset'] || ($offset === $bestMatch['offset'] && strlen($normalizedAlias) > strlen($bestMatch['normalized_alias']))) {
                $bestMatch = [
                    'display_alias' => $alias,
                    'normalized_alias' => $normalizedAlias,
                    'offset' => $offset,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * @return array{quantity_grams: int|null, quantity_text: string|null}
     */
    private function extractQuantity(string $normalizedMessage, string $normalizedAlias, bool $isCookingFat): array
    {
        $escapedAlias = preg_quote($normalizedAlias, '/');
        $measurementMatch = $this->firstRegexMatch($normalizedMessage, [
            "/(?P<value>\d+(?:[\.,]\d+)?)\s*(?P<unit>kg|g|ml)\s*(?:de\s+)?{$escapedAlias}/",
            "/{$escapedAlias}\s*[,;-]?\s*(?P<value>\d+(?:[\.,]\d+)?)\s*(?P<unit>kg|g|ml)/",
        ]);

        if ($measurementMatch !== null) {
            $grams = $this->measurementToGrams((float) str_replace(',', '.', $measurementMatch['value']), $measurementMatch['unit']);

            return [
                'quantity_grams' => $grams,
                'quantity_text' => null,
            ];
        }

        $caseMeasurementMatch = $this->firstRegexMatch($normalizedMessage, [
            "/(?P<value>\d+(?:[\.,]\d+)?)\s*(?P<unit>colher(?:es)? de sopa|colher(?:es)? de cha|unidade(?:s)?)\s*(?:de\s+)?{$escapedAlias}/",
            "/{$escapedAlias}\s*[,;-]?\s*(?P<value>\d+(?:[\.,]\d+)?)\s*(?P<unit>colher(?:es)? de sopa|colher(?:es)? de cha|unidade(?:s)?)/",
        ]);

        if ($caseMeasurementMatch !== null) {
            $quantityValue = str_replace(',', '.', $caseMeasurementMatch['value']);
            $quantityUnit = $caseMeasurementMatch['unit'];
            $quantityText = trim("{$quantityValue} {$quantityUnit}");

            return [
                'quantity_grams' => null,
                'quantity_text' => $quantityText,
            ];
        }

        if ($isCookingFat && preg_match('/\buma\s+colher\s+de\s+sopa\b/', $normalizedMessage)) {
            return [
                'quantity_grams' => null,
                'quantity_text' => '1 colher de sopa',
            ];
        }

        return [
            'quantity_grams' => null,
            'quantity_text' => null,
        ];
    }

    private function measurementToGrams(float $value, string $unit): int
    {
        return match ($unit) {
            'kg' => (int) round($value * 1000),
            'g', 'ml' => (int) round($value),
            default => (int) round($value),
        };
    }

    private function buildDescription(string $displayAlias, string $normalizedAlias, string $normalizedMessage, int $offset): string
    {
        $description = $displayAlias;
        $suffixWindow = substr($normalizedMessage, $offset + strlen($normalizedAlias), 14);

        if (! str_contains($this->normalize($displayAlias), 'sem pele') && str_contains($suffixWindow, 'sem pele')) {
            $description .= ' sem pele';
        }

        return $description;
    }

    private function detectContext(string $normalizedMessage, int $offset, bool $isCookingFat): ?string
    {
        $window = substr($normalizedMessage, max(0, $offset - 40), 100);

        if ($isCookingFat && preg_match('/(fiz(?: ele| ela)? com|feito com|fritei(?: no| com)?|grelhei com|assado com|usei no preparo|usad[ao] no preparo|no preparo)/', $window)) {
            return 'usada no preparo';
        }

        foreach (self::DIRECT_CONSUMPTION_KEYWORDS as $keyword) {
            if (str_contains($window, $keyword)) {
                return match ($keyword) {
                    'por cima' => 'servido por cima',
                    'molho' => 'virou molho no prato',
                    'recheio' => 'consumido como recheio',
                    'cobertura' => 'consumido como cobertura',
                    'caldo' => 'consumido no caldo',
                    default => $keyword,
                };
            }
        }

        return null;
    }

    private function detectCompositeMealTotalGrams(string $normalizedMessage): ?int
    {
        if (! $this->containsCompositeKeyword($normalizedMessage)) {
            return null;
        }

        if (! preg_match('/(?:marmita|quentinha|prato feito|pf|prato)[^\d]{0,15}(?P<value>\d+(?:[\.,]\d+)?)\s*(?P<unit>kg|g)\b/', $normalizedMessage, $matches)) {
            return null;
        }

        return $this->measurementToGrams((float) str_replace(',', '.', $matches['value']), $matches['unit']);
    }

    private function containsCompositeKeyword(string $normalizedMessage): bool
    {
        return collect(self::COMPOSITE_MEAL_KEYWORDS)->contains(fn (string $keyword) => str_contains($normalizedMessage, $keyword));
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null}>  $items
     */
    private function itemsLackSpecificQuantities(array $items): bool
    {
        return collect($items)->every(fn (array $item) => $item['quantity_grams'] === null && $item['quantity_text'] === null);
    }

    private function inferMealType(string $normalizedMessage): ?string
    {
        return match (true) {
            str_contains($normalizedMessage, 'cafe da manha') => 'cafe_da_manha',
            str_contains($normalizedMessage, 'almoco') || str_contains($normalizedMessage, 'almocei') => 'almoco',
            str_contains($normalizedMessage, 'jantar') || str_contains($normalizedMessage, 'janta') || str_contains($normalizedMessage, 'jantei') => 'jantar',
            str_contains($normalizedMessage, 'lanche') => 'lanche',
            str_contains($normalizedMessage, 'sobremesa') => 'sobremesa',
            default => null,
        };
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null}>  $items
     */
    private function buildParsedSummary(array $items, ?int $mealTotalQuantityGrams): string
    {
        $itemsSummary = collect($items)
            ->map(function (array $item): string {
                $quantity = $item['quantity_grams'] !== null
                    ? " {$item['quantity_grams']}g"
                    : ($item['quantity_text'] !== null ? " {$item['quantity_text']}" : '');
                $context = $item['context'] !== null ? " ({$item['context']})" : '';

                return "{$item['description']}{$quantity}{$context}";
            })
            ->implode('; ');

        if ($mealTotalQuantityGrams !== null) {
            return "Refeição composta identificada com peso total de {$mealTotalQuantityGrams}g. Itens detectados: {$itemsSummary}.";
        }

        return "Itens da refeição identificados: {$itemsSummary}.";
    }

    /**
     * @return array<string, array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}>
     */
    private function references(): array
    {
        /** @var array<string, array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}> $references */
        $references = config('nutrition.estimation.references', []);

        return $references;
    }

    /**
     * @param  list<string>  $patterns
     * @return array<string, string>|null
     */
    private function firstRegexMatch(string $message, array $patterns): ?array
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return array_filter($matches, fn ($key) => is_string($key), ARRAY_FILTER_USE_KEY);
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        return Str::of(Str::ascii($text))
            ->lower()
            ->replace(['\n', '\r'], ' ')
            ->squish()
            ->toString();
    }
}
