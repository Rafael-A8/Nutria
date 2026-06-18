<?php

use App\Models\MealItem;
use App\Models\User;
use App\Services\MealAmbiguityService;
use App\Services\MealEstimationService;
use App\Services\MealService;
use Laravel\Ai\StructuredAnonymousAgent;

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
        ->and($result['assistant_response_guide'])->toContain('calorie-dense ingredients have uncertain quantities')
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

it('uses structured fallback estimation for unknown foods before registration', function () {
    $user = User::factory()->create();

    StructuredAnonymousAgent::fake([
        [
            'items' => [
                [
                    'original_description' => 'kombucha',
                    'can_estimate' => true,
                    'description' => 'kombucha',
                    'quantity_grams' => 300,
                    'calories' => 45,
                    'confidence' => 'medium',
                    'assumptions' => ['Kombucha comum estimada em porção de 300ml.'],
                    'calculation_line' => 'Kombucha 300ml = ~45 kcal.',
                    'clarification_question' => null,
                    'clarification_reason' => null,
                ],
                [
                    'original_description' => 'baião de dois',
                    'can_estimate' => true,
                    'description' => 'baião de dois',
                    'quantity_grams' => 200,
                    'calories' => 260,
                    'confidence' => 'low',
                    'assumptions' => ['Baião de dois estimado como preparo típico com arroz, feijão e gordura.'],
                    'calculation_line' => 'Baião de dois 200g = ~260 kcal.',
                    'clarification_question' => null,
                    'clarification_reason' => null,
                ],
            ],
        ],
    ]);

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->times(3)->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'almoco', [
        ['description' => 'arroz', 'quantity_grams' => 120],
        ['description' => 'kombucha', 'quantity_grams' => 300],
        ['description' => 'baião de dois', 'quantity_grams' => 200],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['next_step'])->toBe('register_meal')
        ->and($result['items_for_registration'])->toHaveCount(3)
        ->and($result['items_for_registration'][0]['description'])->toBe('arroz')
        ->and($result['items_for_registration'][1])->toMatchArray([
            'description' => 'kombucha',
            'quantity_grams' => 300,
            'calories' => 45,
        ])
        ->and($result['items_for_registration'][2])->toMatchArray([
            'description' => 'baião de dois',
            'quantity_grams' => 200,
            'calories' => 260,
        ])
        ->and($result['low_confidence_items'])->toBe([])
        ->and($result['total_calories'])->toBe(459)
        ->and($result['estimated_items'][1]['source'])->toBe('ai_structured_fallback')
        ->and($result['assistant_response_guide'])->toContain('without recalculating')
        ->and($result['assistant_response_guide'])->toContain('calorie-dense ingredients have uncertain quantities');
});

it('asks for clarification when structured fallback cannot estimate unknown foods responsibly', function () {
    $user = User::factory()->create();

    StructuredAnonymousAgent::fake([
        [
            'items' => [
                [
                    'original_description' => 'tacos mexicanos',
                    'can_estimate' => false,
                    'description' => 'tacos mexicanos',
                    'quantity_grams' => null,
                    'calories' => null,
                    'confidence' => 'low',
                    'assumptions' => [],
                    'calculation_line' => '',
                    'clarification_question' => 'Quantos tacos você comeu e qual era o recheio principal?',
                    'clarification_reason' => 'Porção e recheio não informados.',
                ],
            ],
        ],
    ]);

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->once()->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'lanche', [
        ['description' => 'tacos mexicanos', 'quantity_grams' => 200],
    ]);

    expect($result['status'])->toBe('clarification_required')
        ->and($result['next_step'])->toBe('ask_for_clarification')
        ->and($result['items_for_registration'])->toHaveCount(0)
        ->and($result['low_confidence_items'])->toHaveCount(1)
        ->and($result['low_confidence_items'][0]['description'])->toBe('tacos mexicanos')
        ->and($result['total_calories'])->toBeNull()
        ->and($result['clarification_question'])->toBe('Quantos tacos você comeu e qual era o recheio principal?');
});

it('estimates barbecue and beer items from internal references', function () {
    $user = User::factory()->create();

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->times(5)->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'almoco', [
        ['description' => 'contra file', 'quantity_grams' => 250],
        ['description' => 'linguica cofril', 'quantity_text' => '1 unidades'],
        ['description' => 'asas de frango', 'quantity_text' => '4 unidades'],
        ['description' => 'feijao tropeiro', 'quantity_text' => 'prato médio'],
        ['description' => 'amistel', 'quantity_grams' => 1892, 'quantity_text' => '4 latas de 473ml'],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['next_step'])->toBe('register_meal')
        ->and($result['low_confidence_items'])->toBe([])
        ->and($result['items_for_registration'])->toHaveCount(5)
        ->and($result['total_calories'])->toBe(2425)
        ->and($result['user_facing_summary'])->toContain('feijao tropeiro tem alta variação')
        ->and($result['items_for_registration'])->sequence(
            fn ($item) => $item->toMatchArray(['description' => 'contra file', 'quantity_grams' => 250, 'calories' => 625]),
            fn ($item) => $item->toMatchArray(['description' => 'linguica cofril', 'quantity_grams' => 60, 'calories' => 178]),
            fn ($item) => $item->toMatchArray(['description' => 'asas de frango', 'quantity_grams' => 200, 'calories' => 580]),
            fn ($item) => $item->toMatchArray(['description' => 'feijao tropeiro', 'quantity_grams' => 150, 'calories' => 228]),
            fn ($item) => $item->toMatchArray(['description' => 'amistel', 'quantity_grams' => 1892, 'calories' => 814]),
        );
});

it('estimates canjica with calorie dense toppings from internal references', function () {
    $user = User::factory()->create();

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->times(4)->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'almoco', [
        ['description' => 'canjica doce', 'quantity_grams' => 500],
        ['description' => 'doce de leite', 'quantity_grams' => 30],
        ['description' => 'leite condesado', 'quantity_grams' => 30],
        ['description' => 'coco ralado', 'quantity_grams' => 20],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['low_confidence_items'])->toBe([])
        ->and($result['items_for_registration'])->toHaveCount(4)
        ->and($result['total_calories'])->toBe(827)
        ->and($result['user_facing_summary'])->toContain('canjica doce tem alta variação')
        ->and($result['user_facing_summary'])->toContain('coco ralado tem alta variação')
        ->and($result['items_for_registration'])->sequence(
            fn ($item) => $item->toMatchArray(['description' => 'canjica doce', 'quantity_grams' => 500, 'calories' => 560]),
            fn ($item) => $item->toMatchArray(['description' => 'doce de leite', 'quantity_grams' => 30, 'calories' => 92]),
            fn ($item) => $item->toMatchArray(['description' => 'leite condesado', 'quantity_grams' => 30, 'calories' => 94]),
            fn ($item) => $item->toMatchArray(['description' => 'coco ralado', 'quantity_grams' => 20, 'calories' => 81]),
        );
});

it('distinguishes farofa from plain cassava flour', function () {
    $user = User::factory()->create();

    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')->twice()->andReturn(collect());

    $service = new MealEstimationService($mealService, new MealAmbiguityService);

    $result = $service->estimate($user, 'almoco', [
        ['description' => 'farofa', 'quantity_grams' => 30],
        ['description' => 'farinha de mandioca', 'quantity_grams' => 20],
    ]);

    expect($result['status'])->toBe('estimated')
        ->and($result['total_calories'])->toBe(194)
        ->and($result['user_facing_summary'])->toContain('farofa tem alta variação')
        ->and($result['items_for_registration'])->sequence(
            fn ($item) => $item->toMatchArray(['description' => 'farofa', 'quantity_grams' => 30, 'calories' => 122]),
            fn ($item) => $item->toMatchArray(['description' => 'farinha de mandioca', 'quantity_grams' => 20, 'calories' => 72]),
        );
});
