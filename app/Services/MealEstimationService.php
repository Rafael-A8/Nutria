<?php

namespace App\Services;

use App\Models\MealItem;
use App\Models\User;
use Illuminate\Support\Str;

class MealEstimationService
{
    public function __construct(
        private ?MealService $mealService = null,
        private ?MealAmbiguityService $ambiguityService = null,
    ) {
        $this->mealService ??= new MealService;
        $this->ambiguityService ??= new MealAmbiguityService;
    }

    /**
     * @param  list<array{description: string, quantity_grams?: int|null, quantity_text?: string|null, context?: string|null}>  $items
     * @return array{
     *     status: 'estimated'|'clarification_required',
     *     meal_type: string,
     *     next_step: 'register_meal'|'ask_for_clarification',
     *     items_for_registration: list<array{description: string, quantity_grams: int|null, calories: int}>,
     *     estimated_items: list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string}>,
     *     total_calories: int|null,
     *     assumptions: list<string>,
     *     calculation_lines: list<string>,
     *     user_facing_summary: string,
     *     assistant_response_guide: string,
     *     clarification_question: string|null,
     *     clarification_reason: string|null
     * }
     */
    public function estimate(User $user, string $mealType, array $items): array
    {
        $itemsForRegistration = [];
        $estimatedItems = [];
        $allAssumptions = [];
        $calculationLines = [];
        $totalCalories = 0;

        foreach ($items as $item) {
            $estimate = $this->estimateItem($user, $item);

            if ($estimate['status'] === 'clarification_required') {
                return [
                    'status' => 'clarification_required',
                    'meal_type' => $mealType,
                    'next_step' => 'ask_for_clarification',
                    'items_for_registration' => $itemsForRegistration,
                    'estimated_items' => $estimatedItems,
                    'total_calories' => null,
                    'assumptions' => array_values(array_unique($allAssumptions)),
                    'calculation_lines' => $calculationLines,
                    'user_facing_summary' => $this->buildClarificationSummary($estimate['clarification_question']),
                    'assistant_response_guide' => $this->clarificationAssistantGuide(),
                    'clarification_question' => $estimate['clarification_question'],
                    'clarification_reason' => $estimate['clarification_reason'],
                ];
            }

            $itemsForRegistration[] = [
                'description' => $estimate['description'],
                'quantity_grams' => $estimate['quantity_grams'],
                'calories' => $estimate['calories'],
            ];

            $estimatedItems[] = [
                'original_description' => $estimate['original_description'],
                'description' => $estimate['description'],
                'quantity_grams' => $estimate['quantity_grams'],
                'calories' => $estimate['calories'],
                'source' => $estimate['source'],
                'assumptions' => $estimate['assumptions'],
                'calculation_line' => $estimate['calculation_line'],
            ];

            $allAssumptions = [...$allAssumptions, ...$estimate['assumptions']];
            $calculationLines[] = $estimate['calculation_line'];
            $totalCalories += $estimate['calories'];
        }

        $assumptions = array_values(array_unique($allAssumptions));

        return [
            'status' => 'estimated',
            'meal_type' => $mealType,
            'next_step' => 'register_meal',
            'items_for_registration' => $itemsForRegistration,
            'estimated_items' => $estimatedItems,
            'total_calories' => $totalCalories,
            'assumptions' => $assumptions,
            'calculation_lines' => $calculationLines,
            'user_facing_summary' => $this->buildEstimatedSummary($mealType, $estimatedItems, $totalCalories, $assumptions),
            'assistant_response_guide' => $this->estimatedAssistantGuide(),
            'clarification_question' => null,
            'clarification_reason' => null,
        ];
    }

    /**
     * @param  array{description: string, quantity_grams?: int|null, quantity_text?: string|null, context?: string|null}  $item
     * @return array{
     *     status: 'estimated'|'clarification_required',
     *     original_description: string,
     *     description?: string,
     *     quantity_grams?: int|null,
     *     calories?: int,
     *     source?: string,
     *     assumptions?: list<string>,
     *     calculation_line?: string,
     *     clarification_question?: string,
     *     clarification_reason?: string
     * }
     */
    private function estimateItem(User $user, array $item): array
    {
        $originalDescription = trim($item['description']);
        $quantityText = $this->cleanOptionalText($item['quantity_text'] ?? null);
        $context = $this->cleanOptionalText($item['context'] ?? null);
        $reference = $this->referenceFor($originalDescription);
        $historyMatch = $this->bestHistoryMatch($user, $originalDescription);
        $resolvedQuantityGrams = $this->resolveQuantityGrams(
            description: $originalDescription,
            explicitQuantityGrams: $item['quantity_grams'] ?? null,
            quantityText: $quantityText,
            reference: $reference,
            historyMatch: $historyMatch,
        );

        $ambiguity = $this->ambiguityService->assess(
            description: $originalDescription,
            quantityGrams: $resolvedQuantityGrams,
            quantityText: $quantityText,
            context: $context,
            isCookingFat: $reference['is_cooking_fat'] ?? false,
            hasReference: $reference !== null,
            hasHistory: $historyMatch !== null,
        );

        if ($ambiguity['requires_clarification']) {
            return [
                'status' => 'clarification_required',
                'original_description' => $originalDescription,
                'clarification_question' => $ambiguity['clarification_question'],
                'clarification_reason' => $ambiguity['reason'],
            ];
        }

        if (($reference['is_cooking_fat'] ?? false) && $ambiguity['treat_as_preparation_only']) {
            return $this->estimatePreparationFat($originalDescription, $resolvedQuantityGrams, $reference);
        }

        if ($historyMatch !== null) {
            return $this->estimateFromHistory($originalDescription, $resolvedQuantityGrams, $historyMatch, $reference);
        }

        if ($reference !== null) {
            return $this->estimateFromReference($originalDescription, $resolvedQuantityGrams, $reference);
        }

        return [
            'status' => 'clarification_required',
            'original_description' => $originalDescription,
            'clarification_question' => "Você consegue detalhar melhor {$originalDescription} para eu estimar com segurança?",
            'clarification_reason' => 'Item sem base interna para estimativa.',
        ];
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}  $reference
     * @return array{status: 'estimated', original_description: string, description: string, quantity_grams: int, calories: int, source: string, assumptions: list<string>, calculation_line: string}
     */
    private function estimatePreparationFat(string $originalDescription, int $resolvedQuantityGrams, array $reference): array
    {
        $consumedQuantityGrams = max(1, (int) round($resolvedQuantityGrams * $this->preparationRetentionFactor()));
        $calories = $this->caloriesFromReference($reference, $consumedQuantityGrams);
        $retentionPercentage = (int) round($this->preparationRetentionFactor() * 100);
        $caloriesPer100g = $reference['calories_per_100g'] ?? 0;

        return [
            'status' => 'estimated',
            'original_description' => $originalDescription,
            'description' => "{$originalDescription} (absorção estimada do preparo)",
            'quantity_grams' => $consumedQuantityGrams,
            'calories' => $calories,
            'source' => 'reference_table_preparation_retention',
            'assumptions' => [
                "{$originalDescription} usado no preparo com retenção estimada de 30%.",
            ],
            'calculation_line' => "{$originalDescription} no preparo: {$resolvedQuantityGrams}g usados × {$retentionPercentage}% de retenção = {$consumedQuantityGrams}g consumidos → {$consumedQuantityGrams} × {$caloriesPer100g}/100 = ~{$calories} kcal.",
        ];
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}|null  $reference
     * @return array{status: 'estimated', original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string}
     */
    private function estimateFromHistory(string $originalDescription, ?int $resolvedQuantityGrams, MealItem $historyMatch, ?array $reference): array
    {
        $historyQuantity = $historyMatch->quantity_grams;
        $quantityGrams = $resolvedQuantityGrams ?? $historyQuantity;
        $assumptions = [
            "Estimativa baseada em item semelhante do histórico: {$historyMatch->description}.",
        ];

        if ($quantityGrams !== null && $historyQuantity !== null && $historyQuantity > 0) {
            $calories = (int) round($historyMatch->calories * ($quantityGrams / $historyQuantity));
            $assumptions[] = 'Calorias ajustadas proporcionalmente pela gramagem informada.';
            $calculationLine = "{$originalDescription} {$quantityGrams}g → histórico semelhante {$historyMatch->description} ({$historyQuantity}g = {$historyMatch->calories} kcal) → ~{$calories} kcal.";
        } else {
            $calories = (int) $historyMatch->calories;
            $quantityGrams ??= $reference['default_grams'] ?? null;
            $assumptions[] = 'Mantido valor calórico do item histórico por falta de gramagem específica.';
            $calculationLine = "{$originalDescription} → histórico semelhante {$historyMatch->description} = ~{$calories} kcal.";
        }

        return [
            'status' => 'estimated',
            'original_description' => $originalDescription,
            'description' => $originalDescription,
            'quantity_grams' => $quantityGrams,
            'calories' => $calories,
            'source' => 'user_history',
            'assumptions' => $assumptions,
            'calculation_line' => $calculationLine,
        ];
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}  $reference
     * @return array{status: 'estimated', original_description: string, description: string, quantity_grams: int, calories: int, source: string, assumptions: list<string>, calculation_line: string}
     */
    private function estimateFromReference(string $originalDescription, ?int $resolvedQuantityGrams, array $reference): array
    {
        $quantityGrams = $resolvedQuantityGrams ?? $reference['default_grams'];
        $calories = $this->caloriesFromReference($reference, $quantityGrams);
        $assumptions = [];
        $calculationLine = isset($reference['calories_per_100g'])
            ? "{$originalDescription} {$quantityGrams}g → {$quantityGrams} × {$reference['calories_per_100g']}/100 = ~{$calories} kcal."
            : "{$originalDescription} {$quantityGrams}g → porção padrão da base interna = ~{$calories} kcal.";

        if ($resolvedQuantityGrams === null) {
            $assumptions[] = "Porção padrão assumida para {$originalDescription}: {$quantityGrams}g.";
        }

        return [
            'status' => 'estimated',
            'original_description' => $originalDescription,
            'description' => $originalDescription,
            'quantity_grams' => $quantityGrams,
            'calories' => $calories,
            'source' => 'reference_table',
            'assumptions' => $assumptions,
            'calculation_line' => $calculationLine,
        ];
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}  $reference
     */
    private function caloriesFromReference(array $reference, int $quantityGrams): int
    {
        if (isset($reference['default_calories']) && $quantityGrams === $reference['default_grams']) {
            return $reference['default_calories'];
        }

        return (int) round($quantityGrams * (($reference['calories_per_100g'] ?? 0) / 100));
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}|null  $reference
     */
    private function resolveQuantityGrams(
        string $description,
        ?int $explicitQuantityGrams,
        ?string $quantityText,
        ?array $reference,
        ?MealItem $historyMatch,
    ): ?int {
        if ($explicitQuantityGrams !== null) {
            return $explicitQuantityGrams;
        }

        if ($quantityText !== null) {
            $parsedQuantity = $this->parseQuantityText($quantityText, $reference);

            if ($parsedQuantity !== null) {
                return $parsedQuantity;
            }
        }

        if ($historyMatch?->quantity_grams) {
            return $historyMatch->quantity_grams;
        }

        return $reference['default_grams'] ?? null;
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}|null  $reference
     */
    private function parseQuantityText(string $quantityText, ?array $reference): ?int
    {
        $normalizedText = $this->normalize($quantityText);
        $number = $this->extractLeadingNumber($normalizedText);

        if ($number === null) {
            return null;
        }

        if (str_contains($normalizedText, ' g')) {
            return (int) round($number);
        }

        if (str_contains($normalizedText, ' ml')) {
            return (int) round($number);
        }

        if (str_contains($normalizedText, 'colher de sopa') || str_contains($normalizedText, 'colheres de sopa')) {
            $gramsPerSpoon = ($reference['is_cooking_fat'] ?? false)
                ? (int) config('nutrition.estimation.measurements.grams_per_tablespoon_fat', 14)
                : (int) config('nutrition.estimation.measurements.grams_per_tablespoon', 15);

            return (int) round($number * $gramsPerSpoon);
        }

        if (str_contains($normalizedText, 'colher de cha') || str_contains($normalizedText, 'colheres de cha')) {
            return (int) round($number * (int) config('nutrition.estimation.measurements.grams_per_teaspoon', 5));
        }

        if (str_contains($normalizedText, 'unidade') || str_contains($normalizedText, 'unidades')) {
            return isset($reference['default_grams'])
                ? (int) round($number * $reference['default_grams'])
                : null;
        }

        return null;
    }

    /**
     * @return array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool}|null
     */
    private function referenceFor(string $description): ?array
    {
        $normalizedDescription = $this->normalize($description);

        foreach ($this->references() as $reference) {
            if (collect($reference['aliases'])->contains(fn (string $alias) => str_contains($normalizedDescription, $this->normalize($alias)))) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @param  list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string}>  $estimatedItems
     * @param  list<string>  $assumptions
     */
    private function buildEstimatedSummary(string $mealType, array $estimatedItems, int $totalCalories, array $assumptions): string
    {
        $itemsSummary = collect($estimatedItems)
            ->map(function (array $item): string {
                $quantityText = $item['quantity_grams'] !== null ? " {$item['quantity_grams']}g" : '';

                return "{$item['description']}{$quantityText} (~{$item['calories']} kcal)";
            })
            ->implode('; ');

        $summary = "Estimativa do {$this->mealTypeLabel($mealType)} pronta: {$itemsSummary}. Total estimado: {$totalCalories} kcal.";

        if ($assumptions !== []) {
            $summary .= ' Hipóteses consideradas: '.implode(' ', $assumptions);
        }

        return $summary;
    }

    private function buildClarificationSummary(string $clarificationQuestion): string
    {
        return "Antes de registrar essa refeição, preciso confirmar um detalhe para estimar com segurança: {$clarificationQuestion}";
    }

    private function estimatedAssistantGuide(): string
    {
        return 'Use user_facing_summary como base da explicação ao usuário, cite calculation_lines quando ajudar a transparência, mantenha assumptions visíveis se houve hipótese e então registre com items_for_registration sem recalcular.';
    }

    private function clarificationAssistantGuide(): string
    {
        return 'Faça a clarification_question abaixo como sua próxima mensagem principal, explique o motivo em uma frase se ajudar e não chame register_meal até o usuário responder.';
    }

    private function preparationRetentionFactor(): float
    {
        return (float) config('nutrition.estimation.preparation_retention_factor', 0.30);
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

    private function mealTypeLabel(string $mealType): string
    {
        return match ($mealType) {
            'cafe_da_manha' => 'café da manhã',
            'almoco' => 'almoço',
            'lanche' => 'lanche',
            'jantar' => 'jantar',
            'sobremesa' => 'sobremesa',
            default => str_replace('_', ' ', $mealType),
        };
    }

    private function bestHistoryMatch(User $user, string $description): ?MealItem
    {
        return $this->mealService
            ->findSimilarItems($user, $description, limit: 1)
            ->first();
    }

    private function cleanOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmedValue = trim($value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }

    private function extractLeadingNumber(string $text): ?float
    {
        if (! preg_match('/(\d+(?:[\.,]\d+)?)/', $text, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    private function normalize(string $text): string
    {
        return Str::of(Str::ascii($text))
            ->lower()
            ->squish()
            ->toString();
    }
}
