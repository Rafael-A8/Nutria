<?php

namespace App\Services;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Ai\agent;

class MealExtractionService
{
    public function __construct(
        private ?MealMessageParsingService $fallbackParser = null,
    ) {
        $this->fallbackParser ??= new MealMessageParsingService;
    }

    /**
     * @return array{
     *     status: 'parsed'|'clarification_required',
     *     meal_type: string,
     *     next_step: 'estimate_meal'|'ask_for_clarification',
     *     raw_message: string,
     *     consumed_at: string|null,
     *     date_reference: string,
     *     date_resolution: string,
     *     items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null, confidence: string}>,
     *     meal_total_quantity_grams: int|null,
     *     is_composite_meal: bool,
     *     user_facing_summary: string,
     *     assistant_response_guide: string,
     *     clarification_question: string|null,
     *     clarification_reason: string|null,
     *     extraction_source: string
     * }
     */
    public function parse(string $message, ?string $mealTypeHint = null): array
    {
        $now = Carbon::now($this->timezone());

        try {
            $extraction = $this->extractWithAi($message, $mealTypeHint, $now);

            return $this->normalizeExtraction($message, $mealTypeHint, $extraction, $now);
        } catch (Throwable $exception) {
            Log::warning('Meal structured extraction failed; using deterministic fallback parser.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->normalizeFallbackResult(
                result: $this->fallbackParser->parse($message, $mealTypeHint),
                message: $message,
                now: $now,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractWithAi(string $message, ?string $mealTypeHint, Carbon $now): array
    {
        $response = agent(
            instructions: $this->instructions(),
            schema: fn (JsonSchema $schema): array => $this->schema($schema),
        )->prompt(
            $this->prompt($message, $mealTypeHint, $now),
            provider: 'openai',
            model: 'gpt-4o-mini',
            timeout: 30,
        );

        /** @var array<string, mixed> $structured */
        $structured = $response->structured ?? [];

        return $structured;
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
        You extract meal facts from Brazilian Portuguese chat messages for a nutrition app.
        Return structured facts only. Do not calculate calories.
        Extract only foods and drinks the user actually consumed.
        Keep item descriptions concise in PT-BR, preserving useful brand/preparation words.
        Resolve dates relative to the provided current datetime. If no date is mentioned, use today.
        Use yesterday when the user says ontem, ontem à noite, ontem de manhã, or similar.
        Convert explicit grams, kilograms, milliliters, and container math when clear. Example: 9 latões de 473ml = 4257 grams/ml.
        For units or household portions, keep quantity_text when grams are not certain.
        If the message contains several foods and only one total dish weight without item split, request clarification.
        If no consumed food or drink is identifiable, request clarification.
        PROMPT;
    }

    private function prompt(string $message, ?string $mealTypeHint, Carbon $now): string
    {
        $hint = $mealTypeHint ?: 'none';

        return <<<PROMPT
        Current datetime: {$now->toDateTimeString()}
        Timezone: {$this->timezone()}
        Meal type hint: {$hint}
        Allowed meal types: cafe_da_manha, almoco, lanche, jantar, sobremesa, outro

        User message:
        {$message}
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['parsed', 'clarification_required'])
                ->required(),
            'meal_type' => $schema->string()
                ->enum(['cafe_da_manha', 'almoco', 'lanche', 'jantar', 'sobremesa', 'outro'])
                ->required(),
            'date_reference' => $schema->string()
                ->enum(['today', 'yesterday', 'specific_date', 'unknown'])
                ->required(),
            'consumed_at' => $schema->string()
                ->description('ISO-like datetime in the application timezone when resolvable, otherwise empty string.')
                ->required(),
            'items' => $schema->array()
                ->items($schema->object([
                    'description' => $schema->string()->required(),
                    'quantity_grams' => $schema->integer()->nullable()->required(),
                    'quantity_text' => $schema->string()->nullable()->required(),
                    'context' => $schema->string()->nullable()->required(),
                    'confidence' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
                ])->withoutAdditionalProperties())
                ->required(),
            'meal_total_quantity_grams' => $schema->integer()->nullable()->required(),
            'is_composite_meal' => $schema->boolean()->required(),
            'clarification_question' => $schema->string()->nullable()->required(),
            'clarification_reason' => $schema->string()->nullable()->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extraction
     * @return array{
     *     status: 'parsed'|'clarification_required',
     *     meal_type: string,
     *     next_step: 'estimate_meal'|'ask_for_clarification',
     *     raw_message: string,
     *     consumed_at: string|null,
     *     date_reference: string,
     *     date_resolution: string,
     *     items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null, confidence: string}>,
     *     meal_total_quantity_grams: int|null,
     *     is_composite_meal: bool,
     *     user_facing_summary: string,
     *     assistant_response_guide: string,
     *     clarification_question: string|null,
     *     clarification_reason: string|null,
     *     extraction_source: string
     * }
     */
    private function normalizeExtraction(string $message, ?string $mealTypeHint, array $extraction, Carbon $now): array
    {
        $mealType = $this->allowedMealType($mealTypeHint)
            ?? $this->allowedMealType($extraction['meal_type'] ?? null)
            ?? 'outro';

        $items = $this->normalizeItems($extraction['items'] ?? []);
        $status = ($extraction['status'] ?? null) === 'clarification_required' || $items === []
            ? 'clarification_required'
            : 'parsed';
        $dateContext = $this->resolveConsumedAt(
            message: $message,
            mealType: $mealType,
            dateReference: $this->normalizeDateReference($extraction['date_reference'] ?? null, $message),
            consumedAt: $this->nullableString($extraction['consumed_at'] ?? null),
            now: $now,
        );

        $clarificationQuestion = $this->nullableString($extraction['clarification_question'] ?? null);
        $clarificationReason = $this->nullableString($extraction['clarification_reason'] ?? null);

        if ($status === 'clarification_required') {
            $clarificationQuestion ??= 'Quais alimentos tinha nessa refeição? Se lembrar, me diga também as quantidades ou medidas caseiras.';
            $clarificationReason ??= $items === []
                ? 'No consumed foods or drinks were identified with enough confidence.'
                : 'Meal details need confirmation before estimation.';
        }

        $mealTotalQuantityGrams = $this->nullableInt($extraction['meal_total_quantity_grams'] ?? null);

        return [
            'status' => $status,
            'meal_type' => $mealType,
            'next_step' => $status === 'parsed' ? 'estimate_meal' : 'ask_for_clarification',
            'raw_message' => $message,
            'consumed_at' => $dateContext['consumed_at'],
            'date_reference' => $dateContext['date_reference'],
            'date_resolution' => $dateContext['date_resolution'],
            'items' => $items,
            'meal_total_quantity_grams' => $mealTotalQuantityGrams,
            'is_composite_meal' => (bool) ($extraction['is_composite_meal'] ?? $mealTotalQuantityGrams !== null),
            'user_facing_summary' => $status === 'parsed'
                ? $this->buildParsedSummary($items, $dateContext['consumed_at'])
                : 'Antes de estimar, preciso identificar melhor os detalhes dessa refeição.',
            'assistant_response_guide' => $status === 'parsed'
                ? 'Use meal_type, consumed_at, and items_text exactly as inputs to estimate_meal. Do not calculate calories yourself.'
                : 'Ask clarification_question and do not estimate or register until the user answers.',
            'clarification_question' => $clarificationQuestion,
            'clarification_reason' => $clarificationReason,
            'extraction_source' => 'structured_ai',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{
     *     status: 'parsed'|'clarification_required',
     *     meal_type: string,
     *     next_step: 'estimate_meal'|'ask_for_clarification',
     *     raw_message: string,
     *     consumed_at: string|null,
     *     date_reference: string,
     *     date_resolution: string,
     *     items: list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null, confidence: string}>,
     *     meal_total_quantity_grams: int|null,
     *     is_composite_meal: bool,
     *     user_facing_summary: string,
     *     assistant_response_guide: string,
     *     clarification_question: string|null,
     *     clarification_reason: string|null,
     *     extraction_source: string
     * }
     */
    private function normalizeFallbackResult(array $result, string $message, Carbon $now): array
    {
        $mealType = $this->allowedMealType($result['meal_type'] ?? null) ?? 'outro';
        $dateContext = $this->resolveConsumedAt(
            message: $message,
            mealType: $mealType,
            dateReference: $this->normalizeDateReference(null, $message),
            consumedAt: null,
            now: $now,
        );

        return [
            ...$result,
            'consumed_at' => $dateContext['consumed_at'],
            'date_reference' => $dateContext['date_reference'],
            'date_resolution' => $dateContext['date_resolution'],
            'items' => collect($result['items'] ?? [])
                ->map(fn (array $item): array => [
                    'description' => (string) ($item['description'] ?? ''),
                    'quantity_grams' => $this->nullableInt($item['quantity_grams'] ?? null),
                    'quantity_text' => $this->nullableString($item['quantity_text'] ?? null),
                    'context' => $this->nullableString($item['context'] ?? null),
                    'confidence' => 'medium',
                ])
                ->filter(fn (array $item): bool => trim($item['description']) !== '')
                ->values()
                ->all(),
            'extraction_source' => 'deterministic_fallback',
        ];
    }

    /**
     * @return list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null, confidence: string}>
     */
    private function normalizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item): array {
                return [
                    'description' => Str::of((string) ($item['description'] ?? ''))->squish()->toString(),
                    'quantity_grams' => $this->nullableInt($item['quantity_grams'] ?? null),
                    'quantity_text' => $this->nullableString($item['quantity_text'] ?? null),
                    'context' => $this->nullableString($item['context'] ?? null),
                    'confidence' => in_array($item['confidence'] ?? null, ['high', 'medium', 'low'], true)
                        ? $item['confidence']
                        : 'medium',
                ];
            })
            ->filter(fn (array $item): bool => $item['description'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array{consumed_at: string, date_reference: string, date_resolution: string}
     */
    private function resolveConsumedAt(
        string $message,
        string $mealType,
        string $dateReference,
        ?string $consumedAt,
        Carbon $now,
    ): array {
        if ($consumedAt !== null) {
            try {
                $parsed = Carbon::parse($consumedAt, $this->timezone())->timezone($this->timezone());

                return [
                    'consumed_at' => $parsed->toDateTimeString(),
                    'date_reference' => $dateReference,
                    'date_resolution' => 'structured_datetime',
                ];
            } catch (Throwable) {
            }
        }

        $date = match ($dateReference) {
            'yesterday' => $now->copy()->subDay(),
            default => $now->copy(),
        };

        $resolved = $dateReference === 'today'
            ? $now->copy()
            : $this->defaultConsumedAtForMealType($date, $mealType);

        return [
            'consumed_at' => $resolved->toDateTimeString(),
            'date_reference' => $dateReference,
            'date_resolution' => str_contains($this->normalize($message), 'ontem') ? 'relative_date_from_message' : 'assumed_today',
        ];
    }

    private function defaultConsumedAtForMealType(Carbon $date, string $mealType): Carbon
    {
        [$hour, $minute] = match ($mealType) {
            'cafe_da_manha' => [8, 0],
            'almoco' => [12, 30],
            'lanche' => [16, 0],
            'jantar' => [20, 0],
            'sobremesa' => [21, 0],
            default => [12, 0],
        };

        return $date->copy()->setTime($hour, $minute);
    }

    private function normalizeDateReference(?string $dateReference, string $message): string
    {
        $normalizedMessage = $this->normalize($message);

        if (str_contains($normalizedMessage, 'ontem')) {
            return 'yesterday';
        }

        if (in_array($dateReference, ['today', 'yesterday', 'specific_date'], true)) {
            return $dateReference;
        }

        return 'today';
    }

    /**
     * @param  list<array{description: string, quantity_grams: int|null, quantity_text: string|null, context: string|null, confidence: string}>  $items
     */
    private function buildParsedSummary(array $items, ?string $consumedAt): string
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

        return "Itens da refeição identificados para {$consumedAt}: {$itemsSummary}.";
    }

    private function allowedMealType(mixed $mealType): ?string
    {
        return is_string($mealType) && in_array($mealType, ['cafe_da_manha', 'almoco', 'lanche', 'jantar', 'sobremesa', 'outro'], true)
            ? $mealType
            : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::of((string) $value)->squish()->toString();

        return $value === '' ? null : $value;
    }

    private function normalize(string $text): string
    {
        return Str::of(Str::ascii($text))
            ->lower()
            ->squish()
            ->toString();
    }

    private function timezone(): string
    {
        return (string) config('app.timezone');
    }
}
