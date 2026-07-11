<?php

use App\Nutrition\Domain\Enums\ComponentRole;
use App\Nutrition\Domain\Enums\QuantitySource;
use App\Nutrition\Domain\Enums\QuantityType;
use App\Nutrition\Infrastructure\Legacy\LegacyMealParserInterpretationAdapter;

function legacyMealParserInterpretationAdapter(): LegacyMealParserInterpretationAdapter
{
    return new LegacyMealParserInterpretationAdapter(new DateTimeZone('America/Sao_Paulo'));
}

it('keeps 120g with tilapia and leaves butter unquantified', function () {
    $rawMessage = '120g de tilápia grelhada na manteiga';
    $draft = legacyMealParserInterpretationAdapter()->fromParserResult($rawMessage, [
        'meal_type' => 'jantar',
        'items' => [
            ['description' => 'tilapia', 'quantity_grams' => 120, 'quantity_text' => null, 'context' => null],
            ['description' => 'manteiga', 'quantity_grams' => null, 'quantity_text' => null, 'context' => 'usada no preparo'],
        ],
    ]);
    $components = $draft->meal->entries[0]->components;

    expect($draft->rawMessage)->toBe($rawMessage)
        ->and($draft->source)->toBe('legacy_parser')
        ->and($components)->toHaveCount(2)
        ->and($components[0]->interpretedFoodText)->toBe('tilapia')
        ->and($components[0]->quantity?->type)->toBe(QuantityType::Exact)
        ->and($components[0]->quantity?->amount)->toBe(120.0)
        ->and($components[0]->quantity?->gramAmount)->toBe(120.0)
        ->and($components[1]->interpretedFoodText)->toBe('manteiga')
        ->and($components[1]->quantity)->toBeNull()
        ->and(array_map(fn ($component) => $component->role, $components))->toBe([
            ComponentRole::Unknown,
            ComponentRole::Unknown,
        ])
        ->and(array_map(fn ($component) => $component->preparations, $components))->toBe([[], []])
        ->and(collect($components)->contains(
            fn ($component): bool => $component->interpretedFoodText !== 'tilapia' && $component->quantity?->gramAmount === 120.0,
        ))->toBeFalse();
});

it('does not infer preparation for unquantified grilled tilapia', function () {
    $rawMessage = 'tilápia grelhada';
    $draft = legacyMealParserInterpretationAdapter()->fromParserResult($rawMessage, [
        'items' => [
            ['description' => 'tilapia', 'quantity_grams' => null, 'quantity_text' => null],
        ],
    ]);
    $component = $draft->meal->entries[0]->components[0];

    expect($draft->rawMessage)->toBe($rawMessage)
        ->and($draft->meal->entries[0]->originalText)->toBe($rawMessage)
        ->and($component->interpretedFoodText)->toBe('tilapia')
        ->and($component->quantity)->toBeNull()
        ->and($component->preparations)->toBe([])
        ->and($component->role)->toBe(ComponentRole::Unknown);
});

it('keeps a mixed meal total on the entry without distributing it to components', function () {
    $draft = legacyMealParserInterpretationAdapter()->fromParserResult('350g de arroz, frango e legumes', [
        'meal_type' => 'almoco',
        'meal_total_quantity_grams' => 350,
        'items' => [
            ['description' => 'arroz', 'quantity_grams' => null, 'quantity_text' => null],
            ['description' => 'frango', 'quantity_grams' => null, 'quantity_text' => null],
            ['description' => 'legumes', 'quantity_grams' => null, 'quantity_text' => null],
        ],
        'clarification_question' => 'Você consegue dividir aproximadamente as quantidades?',
        'clarification_reason' => 'Peso total informado sem divisão por item.',
    ]);
    $entry = $draft->meal->entries[0];

    expect($entry->quantity?->type)->toBe(QuantityType::EntryTotal)
        ->and($entry->quantity?->source)->toBe(QuantitySource::UserReported)
        ->and($entry->quantity?->amount)->toBe(350.0)
        ->and($entry->quantity?->unit)->toBe('g')
        ->and($entry->quantity?->gramAmount)->toBeNull()
        ->and(array_map(fn ($component) => $component->interpretedFoodText, $entry->components))->toBe([
            'arroz',
            'frango',
            'legumes',
        ])
        ->and(array_map(fn ($component) => $component->quantity, $entry->components))->toBe([null, null, null])
        ->and($draft->clarificationQuestion)->toBe('Você consegue dividir aproximadamente as quantidades?')
        ->and($draft->clarificationReason)->toBe('Peso total informado sem divisão por item.');
});

it('preserves vague oil quantity text without converting it to grams', function () {
    $draft = legacyMealParserInterpretationAdapter()->fromParserResult('um pouco de azeite', [
        'items' => [
            ['description' => 'azeite', 'quantity_grams' => null, 'quantity_text' => 'um pouco'],
        ],
    ]);
    $quantity = $draft->meal->entries[0]->components[0]->quantity;

    expect($quantity?->type)->toBe(QuantityType::Vague)
        ->and($quantity?->source)->toBe(QuantitySource::UserReported)
        ->and($quantity?->measureText)->toBe('um pouco')
        ->and($quantity?->amount)->toBeNull()
        ->and($quantity?->unit)->toBeNull()
        ->and($quantity?->gramAmount)->toBeNull()
        ->and($quantity?->packageFraction)->toBeNull()
        ->and($quantity?->sizeText)->toBeNull();
});

it('preserves household quantity text as vague without converting or classifying it', function () {
    $draft = legacyMealParserInterpretationAdapter()->fromParserResult('duas colheres de arroz', [
        'items' => [
            ['description' => 'arroz', 'quantity_grams' => null, 'quantity_text' => 'duas colheres'],
        ],
    ]);
    $quantity = $draft->meal->entries[0]->components[0]->quantity;

    expect($quantity?->type)->toBe(QuantityType::Vague)
        ->and($quantity?->measureText)->toBe('duas colheres')
        ->and($quantity?->amount)->toBeNull()
        ->and($quantity?->unit)->toBeNull()
        ->and($quantity?->gramAmount)->toBeNull();
});

it('uses the injected timezone and caller source without reading extraction metadata', function () {
    $adapter = legacyMealParserInterpretationAdapter();
    $validDraft = $adapter->fromParserResult('arroz no almoço', [
        'consumed_at' => '2026-07-11 12:30:00',
        'extraction_source' => 'structured_ai',
        'items' => [],
    ], 'caller_owned_source');
    $invalidDraft = $adapter->fromParserResult('arroz no almoço', [
        'consumed_at' => 'invalid timestamp',
        'items' => [],
    ]);

    expect($validDraft->source)->toBe('caller_owned_source')
        ->and($validDraft->meal->consumedAt?->format('Y-m-d H:i:s'))->toBe('2026-07-11 12:30:00')
        ->and($validDraft->meal->consumedAt?->getTimezone()->getName())->toBe('America/Sao_Paulo')
        ->and($validDraft->meal->entries)->toHaveCount(1)
        ->and($validDraft->meal->entries[0]->components)->toBe([])
        ->and($invalidDraft->meal->consumedAt)->toBeNull();
});
