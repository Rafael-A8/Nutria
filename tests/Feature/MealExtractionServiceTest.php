<?php

use App\Services\MealExtractionService;
use Illuminate\Support\Carbon;
use Laravel\Ai\StructuredAnonymousAgent;

it('extracts yesterday meal datetime and container quantities with structured ai', function () {
    try {
        Carbon::setTestNow(Carbon::parse('2026-06-14 19:00:00', config('app.timezone')));

        StructuredAnonymousAgent::fake(function (string $prompt, $attachments, $provider, string $model): array {
            expect($prompt)->toContain('Current datetime: 2026-06-14 19:00:00')
                ->and($prompt)->toContain('ontem à noite tomei 9 latões de Amstel 473ml')
                ->and($provider->name())->toBe('openai')
                ->and($model)->toBe('gpt-4o-mini');

            return [
                'status' => 'parsed',
                'meal_type' => 'jantar',
                'date_reference' => 'yesterday',
                'consumed_at' => '',
                'items' => [
                    [
                        'description' => 'Amstel',
                        'quantity_grams' => 4257,
                        'quantity_text' => '9 latões de 473ml',
                        'context' => null,
                        'confidence' => 'high',
                    ],
                    [
                        'description' => 'frango',
                        'quantity_grams' => null,
                        'quantity_text' => null,
                        'context' => null,
                        'confidence' => 'medium',
                    ],
                    [
                        'description' => 'batata frita',
                        'quantity_grams' => null,
                        'quantity_text' => null,
                        'context' => null,
                        'confidence' => 'medium',
                    ],
                ],
                'meal_total_quantity_grams' => null,
                'is_composite_meal' => false,
                'clarification_question' => null,
                'clarification_reason' => null,
            ];
        });

        $result = (new MealExtractionService)->parse('ontem à noite tomei 9 latões de Amstel 473ml e comi frango com fritas');

        expect($result['status'])->toBe('parsed')
            ->and($result['meal_type'])->toBe('jantar')
            ->and($result['consumed_at'])->toBe('2026-06-13 20:00:00')
            ->and($result['date_reference'])->toBe('yesterday')
            ->and($result['date_resolution'])->toBe('relative_date_from_message')
            ->and($result['items'])->toHaveCount(3)
            ->and($result['items'][0])->toMatchArray([
                'description' => 'Amstel',
                'quantity_grams' => 4257,
                'quantity_text' => '9 latões de 473ml',
                'confidence' => 'high',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('falls back to the deterministic parser if structured extraction fails', function () {
    try {
        Carbon::setTestNow(Carbon::parse('2026-06-14 19:00:00', config('app.timezone')));

        StructuredAnonymousAgent::fake(fn (): never => throw new RuntimeException('provider unavailable'));

        $result = (new MealExtractionService)->parse('tomei 9 latões de Amistel 473ml', 'jantar');

        expect($result['status'])->toBe('parsed')
            ->and($result['extraction_source'])->toBe('deterministic_fallback')
            ->and($result['consumed_at'])->toBe('2026-06-14 19:00:00')
            ->and($result['items'])->toHaveCount(1)
            ->and($result['items'][0])->toMatchArray([
                'description' => 'amistel',
                'quantity_grams' => 4257,
                'quantity_text' => '9 latões de 473ml',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});
