<?php

use App\Ai\Tools\EstimateMealTool;
use App\Models\User;
use App\Services\MealEstimationService;
use Laravel\Ai\Tools\Request;

it('returns estimated meal data as json', function () {
    $user = User::factory()->create();

    $estimationService = Mockery::mock(MealEstimationService::class);
    $estimationService->shouldReceive('estimate')
        ->once()
        ->andReturn([
            'status' => 'estimated',
            'meal_type' => 'jantar',
            'next_step' => 'register_meal',
            'items_for_registration' => [
                ['description' => 'arroz', 'quantity_grams' => 130, 'calories' => 166],
            ],
            'estimated_items' => [],
            'total_calories' => 166,
            'assumptions' => [],
            'calculation_lines' => ['Arroz 130g → 130 × 128/100 = ~166 kcal.'],
            'user_facing_summary' => 'Estimativa do jantar pronta: arroz 130g (~166 kcal). Total estimado: 166 kcal.',
            'assistant_response_guide' => 'Use user_facing_summary como base da explicação ao usuário.',
            'clarification_question' => null,
            'clarification_reason' => null,
        ]);

    $tool = new EstimateMealTool($user, $estimationService);

    $result = $tool->handle(new Request([
        'meal_type' => 'jantar',
        'items' => [
            ['description' => 'arroz', 'quantity_grams' => 130],
        ],
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'estimated',
            'meal_type' => 'jantar',
            'next_step' => 'register_meal',
            'total_calories' => 166,
        ]);
});

it('returns clarification payload as json when more detail is required', function () {
    $user = User::factory()->create();

    $estimationService = Mockery::mock(MealEstimationService::class);
    $estimationService->shouldReceive('estimate')
        ->once()
        ->andReturn([
            'status' => 'clarification_required',
            'meal_type' => 'jantar',
            'next_step' => 'ask_for_clarification',
            'items_for_registration' => [],
            'estimated_items' => [],
            'total_calories' => null,
            'assumptions' => [],
            'calculation_lines' => [],
            'user_facing_summary' => 'Antes de registrar essa refeição, preciso confirmar um detalhe para estimar com segurança.',
            'assistant_response_guide' => 'Faça a clarification_question abaixo e não registre ainda.',
            'clarification_question' => 'Essa manteiga ficou só no preparo ou virou molho no prato?',
            'clarification_reason' => 'Ingrediente de preparo com impacto calórico relevante.',
        ]);

    $tool = new EstimateMealTool($user, $estimationService);

    $result = $tool->handle(new Request([
        'meal_type' => 'jantar',
        'items' => [
            ['description' => 'manteiga', 'quantity_text' => '2 colheres de sopa', 'context' => 'usada no preparo'],
        ],
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'clarification_required',
            'next_step' => 'ask_for_clarification',
            'clarification_question' => 'Essa manteiga ficou só no preparo ou virou molho no prato?',
        ]);
});
