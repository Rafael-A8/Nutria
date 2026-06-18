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
        'consumed_at' => '2026-06-14 20:00:00',
        'items' => 'description=arroz; quantity_grams=130; quantity_text=; context=',
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'estimated',
            'meal_type' => 'jantar',
            'next_step' => 'register_meal',
            'consumed_at' => '2026-06-14 20:00:00',
            'registration_allowed' => true,
            'expected_items_count' => 1,
            'pending_items_count' => 0,
            'total_calories' => 166,
        ]);

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR)['items_for_registration_text'])
        ->toContain('description=arroz')
        ->toContain('quantity_grams=130')
        ->toContain('calories=166');
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
        'consumed_at' => '2026-06-14 20:00:00',
        'items' => 'description=manteiga; quantity_grams=; quantity_text=2 colheres de sopa; context=usada no preparo',
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'clarification_required',
            'next_step' => 'ask_for_clarification',
            'registration_allowed' => false,
            'clarification_question' => 'Essa manteiga ficou só no preparo ou virou molho no prato?',
        ]);

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR)['low_confidence_items_text'])->toBe('');
});

it('normalizes line items when optional context is missing', function () {
    $user = User::factory()->create();

    $estimationService = Mockery::mock(MealEstimationService::class);
    $estimationService->shouldReceive('estimate')
        ->once()
        ->withArgs(function (User $passedUser, string $mealType, array $items) use ($user): bool {
            expect($passedUser->is($user))->toBeTrue()
                ->and($mealType)->toBe('almoco')
                ->and($items)->toBe([
                    [
                        'description' => 'asas de frango',
                        'quantity_grams' => null,
                        'quantity_text' => '4 unidades',
                        'context' => null,
                    ],
                ]);

            return true;
        })
        ->andReturn([
            'status' => 'estimated',
            'meal_type' => 'almoco',
            'next_step' => 'register_meal',
            'items_for_registration' => [],
            'estimated_items' => [],
            'low_confidence_items' => [
                ['description' => 'asas de frango', 'quantity_grams' => null, 'quantity_text' => '4 unidades'],
            ],
            'total_calories' => 0,
            'assumptions' => [],
            'calculation_lines' => [],
            'user_facing_summary' => 'Itens fora da base interna.',
            'assistant_response_guide' => 'Estimate low confidence items.',
            'clarification_question' => null,
            'clarification_reason' => null,
        ]);

    $tool = new EstimateMealTool($user, $estimationService);

    $result = $tool->handle(new Request([
        'meal_type' => 'almoco',
        'consumed_at' => '2026-06-14 12:30:00',
        'items' => 'description=asas de frango; quantity_grams=; quantity_text=4 unidades',
    ]));

    $payload = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('estimated')
        ->and($payload['registration_allowed'])->toBeFalse()
        ->and($payload['items_for_registration_text'])->toBe('');
});
