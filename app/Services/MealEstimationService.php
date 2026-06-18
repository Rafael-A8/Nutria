<?php

namespace App\Services;

use App\Models\MealItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Ai\agent;

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
     *     low_confidence_items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>,
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
        $lowConfidenceItems = [];
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
                    'low_confidence_items' => $lowConfidenceItems,
                    'total_calories' => null,
                    'assumptions' => array_values(array_unique($allAssumptions)),
                    'calculation_lines' => $calculationLines,
                    'user_facing_summary' => $this->buildClarificationSummary($estimate['clarification_question']),
                    'assistant_response_guide' => $this->clarificationAssistantGuide(),
                    'clarification_question' => $estimate['clarification_question'],
                    'clarification_reason' => $estimate['clarification_reason'],
                ];
            }

            if ($estimate['status'] === 'low_confidence') {
                $lowConfidenceItems[] = [
                    'description' => $estimate['original_description'],
                    'quantity_grams' => $estimate['quantity_grams'] ?? null,
                    'quantity_text' => $estimate['quantity_text'] ?? null,
                ];

                continue;
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

        if ($lowConfidenceItems !== []) {
            $fallbackResult = $this->estimateLowConfidenceItemsWithAi($mealType, $lowConfidenceItems);

            if ($fallbackResult['unresolved_items'] !== []) {
                return [
                    'status' => 'clarification_required',
                    'meal_type' => $mealType,
                    'next_step' => 'ask_for_clarification',
                    'items_for_registration' => [],
                    'estimated_items' => [],
                    'low_confidence_items' => $fallbackResult['unresolved_items'],
                    'total_calories' => null,
                    'assumptions' => [],
                    'calculation_lines' => [],
                    'user_facing_summary' => $this->buildClarificationSummary($fallbackResult['clarification_question']),
                    'assistant_response_guide' => $this->clarificationAssistantGuide(),
                    'clarification_question' => $fallbackResult['clarification_question'],
                    'clarification_reason' => $fallbackResult['clarification_reason'],
                ];
            }

            foreach ($fallbackResult['estimated_items'] as $estimate) {
                $itemsForRegistration[] = [
                    'description' => $estimate['description'],
                    'quantity_grams' => $estimate['quantity_grams'],
                    'calories' => $estimate['calories'],
                ];

                $estimatedItems[] = $estimate;
                $allAssumptions = [...$allAssumptions, ...$estimate['assumptions']];
                $calculationLines[] = $estimate['calculation_line'];
                $totalCalories += $estimate['calories'];
            }

            $lowConfidenceItems = [];
        }

        $assumptions = array_values(array_unique($allAssumptions));

        $summary = $this->buildEstimatedSummary($mealType, $estimatedItems, $totalCalories, $assumptions);
        $guide = $this->estimatedAssistantGuide();

        return [
            'status' => 'estimated',
            'meal_type' => $mealType,
            'next_step' => 'register_meal',
            'items_for_registration' => $itemsForRegistration,
            'estimated_items' => $estimatedItems,
            'low_confidence_items' => $lowConfidenceItems,
            'total_calories' => $totalCalories,
            'assumptions' => $assumptions,
            'calculation_lines' => $calculationLines,
            'user_facing_summary' => $summary,
            'assistant_response_guide' => $guide,
            'clarification_question' => null,
            'clarification_reason' => null,
        ];
    }

    /**
     * @param  array{description: string, quantity_grams?: int|null, quantity_text?: string|null, context?: string|null}  $item
     * @return array{
     *     status: 'estimated'|'clarification_required'|'low_confidence',
     *     original_description: string,
     *     description?: string,
     *     quantity_grams?: int|null,
     *     calories?: int,
     *     source?: string,
     *     assumptions?: list<string>,
     *     calculation_line?: string,
     *     clarification_question?: string,
     *     clarification_reason?: string,
     *     quantity_text?: string|null
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
            'status' => 'low_confidence',
            'original_description' => $originalDescription,
            'quantity_grams' => $resolvedQuantityGrams,
            'quantity_text' => $quantityText,
        ];
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}  $reference
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
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}|null  $reference
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
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}  $reference
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

        if (($reference['high_variation'] ?? false) === true) {
            $variationNote = $this->cleanOptionalText($reference['variation_note'] ?? null)
                ?? 'o valor pode variar bastante conforme preparo, marca e complementos.';

            $assumptions[] = "{$originalDescription} tem alta variação: {$variationNote}";
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
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}  $reference
     */
    private function caloriesFromReference(array $reference, int $quantityGrams): int
    {
        if (isset($reference['default_calories']) && $quantityGrams === $reference['default_grams']) {
            return $reference['default_calories'];
        }

        return (int) round($quantityGrams * (($reference['calories_per_100g'] ?? 0) / 100));
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}|null  $reference
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
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}|null  $reference
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
     * @return array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}|null
     */
    private function referenceFor(string $description): ?array
    {
        $normalizedDescription = $this->normalize($description);
        $bestReference = null;
        $bestAliasLength = 0;

        foreach ($this->references() as $reference) {
            foreach ($reference['aliases'] as $alias) {
                $normalizedAlias = $this->normalize($alias);
                $aliasLength = strlen($normalizedAlias);

                if ($normalizedAlias === '' || $aliasLength <= $bestAliasLength) {
                    continue;
                }

                if (str_contains($normalizedDescription, $normalizedAlias)) {
                    $bestReference = $reference;
                    $bestAliasLength = $aliasLength;
                }
            }
        }

        return $bestReference;
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

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>  $items
     * @return array{
     *     estimated_items: list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string}>,
     *     unresolved_items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>,
     *     clarification_question: string,
     *     clarification_reason: string
     * }
     */
    private function estimateLowConfidenceItemsWithAi(string $mealType, array $items): array
    {
        try {
            $response = agent(
                instructions: $this->lowConfidenceEstimatorInstructions(),
                schema: fn (JsonSchema $schema): array => $this->lowConfidenceEstimatorSchema($schema),
            )->prompt(
                $this->lowConfidenceEstimatorPrompt($mealType, $items),
                provider: 'openai',
                model: 'gpt-4o-mini',
                timeout: 30,
            );

            /** @var array<string, mixed> $structured */
            $structured = $response->structured ?? [];

            return $this->normalizeLowConfidenceEstimates($items, $structured);
        } catch (Throwable $exception) {
            Log::warning('Low confidence meal estimation failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'items' => $items,
            ]);

            return [
                'estimated_items' => [],
                'unresolved_items' => $items,
                'clarification_question' => $this->clarificationQuestionForUnresolvedItems($items),
                'clarification_reason' => 'Structured nutrition fallback estimation failed.',
            ];
        }
    }

    private function lowConfidenceEstimatorInstructions(): string
    {
        return <<<'PROMPT'
        You estimate nutrition values for food items that were not found in the deterministic internal database.
        Return structured data only. Do not register meals.
        Estimate only when the item and portion are specific enough for a responsible rough estimate.
        Prefer cautious, realistic calories for Brazilian foods and common portions.
        If the quantity is too vague for a useful estimate, mark can_estimate=false and provide one short clarification question in PT-BR.
        Assumptions and calculation_line may be in PT-BR because they can be shown to the final user.
        PROMPT;
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>  $items
     */
    private function lowConfidenceEstimatorPrompt(string $mealType, array $items): string
    {
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return <<<PROMPT
        Meal type: {$mealType}
        Items needing fallback estimation:
        {$itemsJson}

        Return one output object per input item, preserving original_description.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function lowConfidenceEstimatorSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object([
                    'original_description' => $schema->string()->required(),
                    'can_estimate' => $schema->boolean()->required(),
                    'description' => $schema->string()->required(),
                    'quantity_grams' => $schema->integer()->nullable()->required(),
                    'calories' => $schema->integer()->nullable()->required(),
                    'confidence' => $schema->string()->enum(['low', 'medium'])->required(),
                    'assumptions' => $schema->array()->items($schema->string())->required(),
                    'calculation_line' => $schema->string()->required(),
                    'clarification_question' => $schema->string()->nullable()->required(),
                    'clarification_reason' => $schema->string()->nullable()->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>  $originalItems
     * @param  array<string, mixed>  $structured
     * @return array{
     *     estimated_items: list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string}>,
     *     unresolved_items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>,
     *     clarification_question: string,
     *     clarification_reason: string
     * }
     */
    private function normalizeLowConfidenceEstimates(array $originalItems, array $structured): array
    {
        $estimatedItems = [];
        $unresolvedItems = [];
        $clarificationQuestions = [];
        $clarificationReasons = [];

        $resultsList = collect($structured['items'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $results = $resultsList
            ->keyBy(fn (array $item): string => $this->normalize($item['original_description'] ?? $item['description'] ?? ''));

        foreach ($originalItems as $index => $item) {
            $result = $results->get($this->normalize($item['description'])) ?? $resultsList->get($index);

            if (! is_array($result) || ($result['can_estimate'] ?? false) !== true || $this->nullablePositiveInt($result['calories'] ?? null) === null) {
                $unresolvedItems[] = $item;
                $clarificationQuestions[] = $this->cleanOptionalText($result['clarification_question'] ?? null);
                $clarificationReasons[] = $this->cleanOptionalText($result['clarification_reason'] ?? null);

                continue;
            }

            $calories = $this->nullablePositiveInt($result['calories']) ?? 0;
            $quantityGrams = $this->nullablePositiveInt($result['quantity_grams'] ?? null) ?? $item['quantity_grams'];
            $description = $this->cleanOptionalText($result['description'] ?? null) ?? $item['description'];
            $assumptions = $this->normalizeStringList($result['assumptions'] ?? []);
            $calculationLine = $this->cleanOptionalText($result['calculation_line'] ?? null)
                ?? "{$description}: fallback estimate = ~{$calories} kcal.";

            $assumptions[] = "Estimativa aproximada por fallback de IA para {$item['description']}.";

            $estimatedItems[] = [
                'original_description' => $item['description'],
                'description' => $description,
                'quantity_grams' => $quantityGrams,
                'calories' => $calories,
                'source' => 'ai_structured_fallback',
                'assumptions' => array_values(array_unique($assumptions)),
                'calculation_line' => $calculationLine,
            ];
        }

        return [
            'estimated_items' => $estimatedItems,
            'unresolved_items' => $unresolvedItems,
            'clarification_question' => collect($clarificationQuestions)->filter()->first()
                ?? $this->clarificationQuestionForUnresolvedItems($unresolvedItems),
            'clarification_reason' => collect($clarificationReasons)->filter()->first()
                ?? 'Some extracted items could not be estimated responsibly.',
        ];
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null}>  $items
     */
    private function clarificationQuestionForUnresolvedItems(array $items): string
    {
        $itemNames = collect($items)->pluck('description')->filter()->implode(', ');

        return "Qual foi aproximadamente a porção de {$itemNames}? Se for industrializado, me diga também a marca.";
    }

    private function estimatedAssistantGuide(): string
    {
        return 'Use user_facing_summary as the base for the user-facing explanation, cite calculation_lines when transparency helps, keep assumptions visible when assumptions were used, and then register with items_for_registration without recalculating. If calorie-dense ingredients have uncertain quantities, clearly state that the estimate may vary and do not present the total as exact.';
    }

    private function clarificationAssistantGuide(): string
    {
        return 'Ask clarification_question as your main next message, briefly explain the reason when useful, and do not call register_meal until the user answers.';
    }

    private function preparationRetentionFactor(): float
    {
        return (float) config('nutrition.estimation.preparation_retention_factor', 0.30);
    }

    /**
     * @return array<string, array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}>
     */
    private function references(): array
    {
        /** @var array<string, array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}> $references */
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

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (int) round((float) $value);

        return $value > 0 ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn (mixed $value): ?string => $this->cleanOptionalText(is_scalar($value) ? (string) $value : null))
            ->filter()
            ->values()
            ->all();
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
