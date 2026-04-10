<?php

use App\Models\MealItem;
use App\Models\User;
use App\Services\MealAmbiguityService;
use App\Services\MealEstimationService;
use App\Services\MealService;

it('estimates common foods deterministically from internal references', function () {
    $user = User::factory()->create();

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->twice()->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'jantar', [
        ['description' => 'arroz', 'quantity_grams' => 130],
        ['description' => 'banana', 'quantity_text' => '1 unidade'],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['next_step'])->toBe('register_meal')
        ->and($result['total_calories'])->toBe(255)
        ->and($result['user_facing_summary'])->toContain('Estimativa do jantar pronta')
        ->and($result['assistant_response_guide'])->toContain('user_facing_summary')
        ->and($result['calculation_lines'])->toHaveCount(2)
        ->and($result['items_for_registration'])->toHaveCount(2)
        ->and($result['items_for_registration'][0])->toMatchArray([
            'description' => 'arroz',
            'quantity_grams' => 130,
            'calories' => 166,
        ])
        ->and($result['items_for_registration'][1])->toMatchArray([
            'description' => 'banana',
            'quantity_grams' => 100,
            'calories' => 89,
        ]);
});

it('asks for clarification before estimating high-impact cooking fat in preparation', function () {
    $user = User::factory()->create();

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->once()->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'jantar', [
        [
            'description' => 'manteiga',
            'quantity_text' => '2 colheres de sopa',
            'context' => 'usada no preparo do frango assado',
        ],
    ]);

    expect($result['status'])->toBe('clarification_required')
        ->and($result['next_step'])->toBe('ask_for_clarification')
        ->and($result['user_facing_summary'])->toContain('Antes de registrar essa refeição')
        ->and($result['clarification_question'])->toContain('ficou só no preparo')
        ->and($result['clarification_reason'])->toContain('impacto calórico relevante');
});

it('estimates low-impact cooking fat in preparation using retention', function () {
    $user = User::factory()->create();

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->once()->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'jantar', [
        [
            'description' => 'azeite',
            'quantity_text' => '1 colher de chá',
            'context' => 'usado no preparo da omelete',
        ],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['total_calories'])->toBe(18)
        ->and($result['calculation_lines'][0])->toContain('30% de retenção')
        ->and($result['items_for_registration'][0]['description'])->toContain('absorção estimada do preparo')
        ->and($result['items_for_registration'][0]['quantity_grams'])->toBe(2);
});

it('can scale calories from a similar historical item', function () {
    $user = User::factory()->create();

    $historyItem = MealItem::make([
        'description' => 'arroz branco',
        'quantity_grams' => 100,
        'calories' => 128,
    ]);

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->once()->andReturn(collect([$historyItem]));

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'almoco', [
        ['description' => 'arroz branco', 'quantity_grams' => 150],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['items_for_registration'][0])->toMatchArray([
            'description' => 'arroz branco',
            'quantity_grams' => 150,
            'calories' => 192,
        ])
        ->and($result['calculation_lines'][0])->toContain('histórico semelhante')
        ->and($result['estimated_items'][0]['source'])->toBe('user_history');
});

it('reads reference calories from configuration instead of hardcoded prompt data', function () {
    $user = User::factory()->create();

    config(['nutrition.estimation.references.arroz branco cozido.calories_per_100g' => 140]);

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->once()->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'almoco', [
        ['description' => 'arroz', 'quantity_grams' => 100],
    ]);

    expect($result['items_for_registration'][0]['calories'])->toBe(140)
        ->and($result['calculation_lines'][0])->toContain('140/100');
});
