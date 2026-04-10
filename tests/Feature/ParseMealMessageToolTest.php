<?php

use App\Ai\Tools\ParseMealMessageTool;
use App\Services\MealMessageParsingService;
use Laravel\Ai\Tools\Request;

it('returns parsed meal payload as json', function () {
    $parsingService = Mockery::mock(MealMessageParsingService::class);
    $parsingService->shouldReceive('parse')
        ->once()
        ->andReturn([
            'status' => 'parsed',
            'meal_type' => 'jantar',
            'next_step' => 'estimate_meal',
            'raw_message' => 'arroz 130g',
            'items' => [
                ['description' => 'arroz', 'quantity_grams' => 130, 'quantity_text' => null, 'context' => null],
            ],
            'meal_total_quantity_grams' => null,
            'is_composite_meal' => false,
            'user_facing_summary' => 'Itens da refeição identificados: arroz 130g.',
            'assistant_response_guide' => 'Use a estrutura retornada em estimate_meal.',
            'clarification_question' => null,
            'clarification_reason' => null,
        ]);

    $tool = new ParseMealMessageTool($parsingService);

    $result = $tool->handle(new Request([
        'message' => 'arroz 130g',
        'meal_type_hint' => 'jantar',
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'parsed',
            'meal_type' => 'jantar',
            'next_step' => 'estimate_meal',
        ]);
});

it('returns clarification payload as json for ambiguous composite meals', function () {
    $parsingService = Mockery::mock(MealMessageParsingService::class);
    $parsingService->shouldReceive('parse')
        ->once()
        ->andReturn([
            'status' => 'clarification_required',
            'meal_type' => 'almoco',
            'next_step' => 'ask_for_clarification',
            'raw_message' => 'marmita de 1kg',
            'items' => [],
            'meal_total_quantity_grams' => 1000,
            'is_composite_meal' => true,
            'user_facing_summary' => 'Entendi que foi uma refeição composta com peso total de 1000g.',
            'assistant_response_guide' => 'Peça a divisão dos itens antes de estimar.',
            'clarification_question' => 'Essa refeição de 1000g parece ser o peso total do conjunto. Você consegue me dizer aproximadamente quanto tinha de cada item?',
            'clarification_reason' => 'Peso total informado para refeição composta sem divisão por item.',
        ]);

    $tool = new ParseMealMessageTool($parsingService);

    $result = $tool->handle(new Request([
        'message' => 'Comi marmita de 1kg com arroz e feijão',
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'clarification_required',
            'next_step' => 'ask_for_clarification',
            'meal_total_quantity_grams' => 1000,
        ]);
});
