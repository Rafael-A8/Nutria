<?php

use App\Services\MealMessageParsingService;

it('parses free-text meal items with quantities and preparation context', function () {
    $service = new MealMessageParsingService;

    $result = $service->parse('Foi arroz, 130g e frango assado sem pele onde fiz ele com 2 colheres de sopa de manteiga');

    expect($result['status'])->toBe('parsed')
        ->and($result['next_step'])->toBe('estimate_meal')
        ->and($result['items'])->toHaveCount(3)
        ->and($result['items'][0])->toMatchArray([
            'description' => 'arroz',
            'quantity_grams' => 130,
        ])
        ->and($result['items'][1]['description'])->toBe('frango assado sem pele')
        ->and($result['items'][2])->toMatchArray([
            'description' => 'manteiga',
            'quantity_grams' => null,
            'quantity_text' => '2 colheres de sopa',
            'context' => 'usada no preparo',
        ]);
});

it('asks for clarification when a composite meal has only a total weight', function () {
    $service = new MealMessageParsingService;

    $result = $service->parse('Comi marmita de 1kg com arroz, feijão, frango e batata. O que você sugere para eu comer amanhã?');

    expect($result['status'])->toBe('clarification_required')
        ->and($result['next_step'])->toBe('ask_for_clarification')
        ->and($result['is_composite_meal'])->toBeTrue()
        ->and($result['meal_total_quantity_grams'])->toBe(1000)
        ->and($result['clarification_reason'])->toContain('Peso total informado para refeição composta')
        ->and($result['clarification_question'])->toContain('peso total do conjunto');
});
