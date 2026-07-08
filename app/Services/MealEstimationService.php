<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
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
     *     estimated_items: list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key?: string|null}>,
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

            $estimatedItem = [
                'original_description' => $estimate['original_description'],
                'description' => $estimate['description'],
                'quantity_grams' => $estimate['quantity_grams'],
                'calories' => $estimate['calories'],
                'source' => $estimate['source'],
                'assumptions' => $estimate['assumptions'],
                'calculation_line' => $estimate['calculation_line'],
            ];

            if (array_key_exists('reference_key', $estimate)) {
                $estimatedItem['reference_key'] = $estimate['reference_key'];
            }

            $this->logEstimatedItem($estimatedItem);
            $estimatedItems[] = $estimatedItem;
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

                $this->logEstimatedItem($estimate);
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
     *     reference_key?: string|null,
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
        $referenceMatch = $this->referenceMatchFor($originalDescription);
        $reference = $referenceMatch['reference'] ?? null;
        $referenceKey = $referenceMatch['key'] ?? null;

        if (
            ($reference['is_cooking_fat'] ?? false) === true
            && $this->quantityAppearsToBelongToPrimaryFood($item['quantity_grams'] ?? null, $quantityText)
            && $this->hasNonFatReferenceMatch($originalDescription, $referenceKey)
        ) {
            return $this->clarifyCookingFatInCompoundItem($originalDescription);
        }

        $resolvedQuantityGrams = $this->resolveQuantityGrams(
            explicitQuantityGrams: $item['quantity_grams'] ?? null,
            quantityText: $quantityText,
            reference: $reference,
        );

        $ambiguity = $this->ambiguityService->assess(
            description: $originalDescription,
            quantityGrams: $resolvedQuantityGrams,
            quantityText: $quantityText,
            context: $context,
            isCookingFat: $reference['is_cooking_fat'] ?? false,
            hasReference: $reference !== null,
            hasHistory: false,
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
            return $this->estimatePreparationFat($originalDescription, $resolvedQuantityGrams, $reference, $referenceKey);
        }

        if ($reference !== null) {
            return $this->estimateFromReference($originalDescription, $resolvedQuantityGrams, $reference, $referenceKey);
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
     * @return array{status: 'estimated', original_description: string, description: string, quantity_grams: int, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key: string|null}
     */
    private function estimatePreparationFat(string $originalDescription, int $resolvedQuantityGrams, array $reference, ?string $referenceKey): array
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
            'reference_key' => $referenceKey,
        ];
    }

    /**
     * @param  array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}|null  $reference
     * @return array{status: 'estimated', original_description: string, description: string, quantity_grams: int, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key: string|null}
     */
    private function estimateFromReference(string $originalDescription, ?int $resolvedQuantityGrams, array $reference, ?string $referenceKey): array
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
            'reference_key' => $referenceKey,
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
        ?int $explicitQuantityGrams,
        ?string $quantityText,
        ?array $reference,
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

        if (($reference['is_cooking_fat'] ?? false) === true) {
            return null;
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
     * @return array{key: string, reference: array{aliases: list<string>, calories_per_100g?: int, default_grams: int, default_calories?: int, is_cooking_fat?: bool, source?: string, confidence?: string, high_variation?: bool, variation_note?: string}}|null
     */
    private function referenceMatchFor(string $description): ?array
    {
        $normalizedDescription = $this->normalize($description);
        $bestMatch = null;
        $bestAliasLength = 0;

        foreach ($this->references() as $key => $reference) {
            foreach ($reference['aliases'] as $alias) {
                $normalizedAlias = $this->normalize($alias);
                $aliasLength = strlen($normalizedAlias);

                if ($normalizedAlias === '' || $aliasLength <= $bestAliasLength) {
                    continue;
                }

                if (str_contains($normalizedDescription, $normalizedAlias)) {
                    $bestMatch = [
                        'key' => $key,
                        'reference' => $reference,
                    ];
                    $bestAliasLength = $aliasLength;
                }
            }
        }

        return $bestMatch;
    }

    private function quantityAppearsToBelongToPrimaryFood(?int $explicitQuantityGrams, ?string $quantityText): bool
    {
        if ($explicitQuantityGrams !== null) {
            return true;
        }

        if ($quantityText === null) {
            return false;
        }

        $normalizedText = $this->normalize($quantityText);

        return preg_match('/\b(g|grama|gramas|kg|quilo|quilos|unidade|unidades|porcao|porcoes)\b/', $normalizedText) === 1;
    }

    private function hasNonFatReferenceMatch(string $description, ?string $selectedReferenceKey): bool
    {
        $normalizedDescription = $this->normalize($description);

        foreach ($this->references() as $key => $reference) {
            if ($key === $selectedReferenceKey || ($reference['is_cooking_fat'] ?? false) === true) {
                continue;
            }

            foreach ($reference['aliases'] as $alias) {
                $normalizedAlias = $this->normalize($alias);

                if ($normalizedAlias !== '' && str_contains($normalizedDescription, $normalizedAlias)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{status: 'clarification_required', original_description: string, clarification_question: string, clarification_reason: string}
     */
    private function clarifyCookingFatInCompoundItem(string $originalDescription): array
    {
        return [
            'status' => 'clarification_required',
            'original_description' => $originalDescription,
            'clarification_question' => 'Qual foi a quantidade aproximada da gordura usada no preparo e ela foi consumida inteira ou apenas usada para grelhar/refogar?',
            'clarification_reason' => 'Cooking fat appears together with another primary food, so the provided quantity must not be applied to the fat.',
        ];
    }

    /**
     * @param  array{original_description?: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key?: string|null, history_item_id?: int|null}  $estimate
     */
    private function logEstimatedItem(array $estimate): void
    {
        $context = [
            'source' => $estimate['source'],
            'normalized_description' => $this->normalize($estimate['original_description'] ?? $estimate['description']),
            'calculation_line' => $estimate['calculation_line'],
        ];

        if (array_key_exists('reference_key', $estimate) && $estimate['reference_key'] !== null) {
            $context['reference_key'] = $estimate['reference_key'];
        }

        if (array_key_exists('history_item_id', $estimate)) {
            $context['history_item_id'] = $estimate['history_item_id'];
        }

        Log::info('nutrition.legacy_estimate.item', $context);
    }

    /**
     * @param  list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key?: string|null}>  $estimatedItems
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
     *     estimated_items: list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key?: string|null}>,
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
                provider: Lab::OpenAI,
                model: 'gpt-4o-mini',
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
     *     estimated_items: list<array{original_description: string, description: string, quantity_grams: int|null, calories: int, source: string, assumptions: list<string>, calculation_line: string, reference_key?: string|null}>,
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
