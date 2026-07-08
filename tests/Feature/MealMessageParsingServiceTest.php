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

it('does not assign tilapia grams to butter when grilled in butter', function () {
    $service = new MealMessageParsingService;

    $result = $service->parse('120g de tilápia grelhada na manteiga', 'jantar');

    expect($result['status'])->toBe('parsed')
        ->and($result['items'])->toHaveCount(2)
        ->and($result['items'][0])->toMatchArray([
            'description' => 'tilapia',
            'quantity_grams' => 120,
        ])
        ->and($result['items'][1])->toMatchArray([
            'description' => 'manteiga',
            'quantity_grams' => null,
            'quantity_text' => null,
            'context' => 'usada no preparo',
        ])
        ->and(collect($result['items'])->contains(fn (array $item): bool => $item['description'] === 'manteiga' && $item['quantity_grams'] === 120))->toBeFalse();
});

it('parses barbecue items and beer cans without dropping relevant foods', function () {
    $service = new MealMessageParsingService;

    $result = $service->parse('almoço foi churrasco. 250g de contra file, 1 linguiça cofril, 4 asas de frango, feijão tropeiro um prato medio, e 4 latas de amistel 473ml');

    expect($result['status'])->toBe('parsed')
        ->and($result['items'])->toHaveCount(5)
        ->and($result['items'])->sequence(
            fn ($item) => $item->toMatchArray(['description' => 'contra file', 'quantity_grams' => 250]),
            fn ($item) => $item->toMatchArray(['description' => 'linguica', 'quantity_text' => '1 unidades']),
            fn ($item) => $item->toMatchArray(['description' => 'asas de frango', 'quantity_text' => '4 unidades']),
            fn ($item) => $item->toMatchArray(['description' => 'feijao tropeiro']),
            fn ($item) => $item->toMatchArray(['description' => 'amistel', 'quantity_grams' => 1892, 'quantity_text' => '4 latas de 473ml']),
        );
});

it('parses beer tall cans with volume', function () {
    $service = new MealMessageParsingService;

    $result = $service->parse('tomei 9 latões de Amistel 473ml', 'jantar');

    expect($result['status'])->toBe('parsed')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0])->toMatchArray([
            'description' => 'amistel',
            'quantity_grams' => 4257,
            'quantity_text' => '9 latões de 473ml',
        ]);
});

it('prefers specific overlapping items and ignores negated sugar', function () {
    $service = new MealMessageParsingService;

    $result = $service->parse('um misto de pão de forma com queijo e presunto e café sem açúcar');

    expect($result['status'])->toBe('parsed')
        ->and(collect($result['items'])->pluck('description')->all())->toBe([
            'pao de forma',
            'queijo',
            'presunto',
            'cafe sem acucar',
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
