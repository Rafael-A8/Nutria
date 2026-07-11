<?php

use App\Nutrition\Domain\Drafts\InterpretationDraft;
use App\Nutrition\Domain\Drafts\MealComponentDraft;
use App\Nutrition\Domain\Drafts\MealDraft;
use App\Nutrition\Domain\Drafts\MealEntryDraft;
use App\Nutrition\Domain\Enums\ComponentRole;
use App\Nutrition\Domain\Enums\PreparationType;
use App\Nutrition\Domain\Enums\QuantitySource;
use App\Nutrition\Domain\Enums\QuantityType;
use App\Nutrition\Domain\ValueObjects\Preparation;
use App\Nutrition\Domain\ValueObjects\Quantity;

/**
 * @param  list<MealComponentDraft>  $components
 */
function interpretationDraftForM12Scenario(
    string $rawMessage,
    array $components,
    ?Quantity $entryQuantity = null,
): InterpretationDraft {
    return new InterpretationDraft(
        rawMessage: $rawMessage,
        source: 'user_message',
        meal: new MealDraft(entries: [
            new MealEntryDraft(
                originalText: $rawMessage,
                quantity: $entryQuantity,
                components: $components,
            ),
        ]),
    );
}

it('defines the canonical domain enums', function () {
    expect(array_column(ComponentRole::cases(), 'value'))->toBe([
        'primary_food',
        'accompaniment',
        'cooking_fat',
        'sauce',
        'topping',
        'condiment',
        'ingredient',
        'filling',
        'beverage_component',
        'unknown',
    ])->and(array_column(PreparationType::cases(), 'value'))->toBe([
        'grilled',
        'fried',
        'roasted',
        'baked',
        'boiled',
        'steamed',
        'sauteed',
        'cooked',
        'raw',
        'mixed',
        'unknown',
    ])->and(array_column(QuantityType::cases(), 'value'))->toBe([
        'exact',
        'household_measure',
        'sized_unit',
        'vague',
        'entry_total',
        'package_fraction',
    ])->and(array_column(QuantitySource::cases(), 'value'))->toBe([
        'user_reported',
        'deterministically_converted',
        'user_clarified',
        'system_assumed',
    ]);
});

it('keeps domain drafts and value objects readonly', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->isFinal())->toBeTrue();
})->with([
    InterpretationDraft::class,
    MealDraft::class,
    MealEntryDraft::class,
    MealComponentDraft::class,
    Quantity::class,
    Preparation::class,
]);

it('preserves interpretation, meal, entry, and clarification context', function () {
    $consumedAt = new DateTimeImmutable('2026-07-10 12:30:00');
    $entry = new MealEntryDraft(
        originalText: 'tilápia grelhada',
        components: [
            new MealComponentDraft(
                originalText: 'tilápia grelhada',
                interpretedFoodText: 'tilápia',
                role: ComponentRole::PrimaryFood,
                preparations: [new Preparation(PreparationType::Grilled)],
            ),
        ],
    );
    $meal = new MealDraft(
        mealType: 'lunch',
        consumedAt: $consumedAt,
        entries: [$entry],
    );
    $draft = new InterpretationDraft(
        rawMessage: 'tilápia grelhada',
        source: 'user_message',
        meal: $meal,
        clarificationQuestion: 'Quanto foi consumido?',
        clarificationReason: 'quantity_missing',
    );

    expect($draft->rawMessage)->toBe('tilápia grelhada')
        ->and($draft->source)->toBe('user_message')
        ->and($draft->meal)->toBe($meal)
        ->and($draft->clarificationQuestion)->toBe('Quanto foi consumido?')
        ->and($draft->clarificationReason)->toBe('quantity_missing')
        ->and($meal->mealType)->toBe('lunch')
        ->and($meal->consumedAt)->toBe($consumedAt)
        ->and($meal->entries)->toBe([$entry])
        ->and($entry->originalText)->toBe('tilápia grelhada');
});

it('keeps 120g with grilled tilapia and leaves butter quantity unknown', function () {
    $tilapia = new MealComponentDraft(
        originalText: '120g de tilápia grelhada',
        interpretedFoodText: 'tilápia',
        role: ComponentRole::PrimaryFood,
        quantity: new Quantity(
            type: QuantityType::Exact,
            source: QuantitySource::UserReported,
            amount: 120,
            unit: 'g',
            gramAmount: 120,
        ),
        preparations: [new Preparation(PreparationType::Grilled)],
    );
    $butter = new MealComponentDraft(
        originalText: 'manteiga',
        interpretedFoodText: 'manteiga',
        role: ComponentRole::CookingFat,
    );
    $draft = interpretationDraftForM12Scenario(
        '120g de tilápia grelhada na manteiga',
        [$tilapia, $butter],
    );

    expect($draft->meal->entries[0]->quantity)->toBeNull()
        ->and($draft->meal->entries[0]->components)->toHaveCount(2)
        ->and($tilapia->interpretedFoodText)->toBe('tilápia')
        ->and($tilapia->role)->toBe(ComponentRole::PrimaryFood)
        ->and($tilapia->quantity?->type)->toBe(QuantityType::Exact)
        ->and($tilapia->quantity?->amount)->toBe(120.0)
        ->and($tilapia->quantity?->unit)->toBe('g')
        ->and($tilapia->quantity?->gramAmount)->toBe(120.0)
        ->and($tilapia->preparations[0]->type)->toBe(PreparationType::Grilled)
        ->and($butter->role)->toBe(ComponentRole::CookingFat)
        ->and($butter->quantity)->toBeNull();
});

it('keeps grilled tilapia as a component with preparation and no quantity', function () {
    $tilapia = new MealComponentDraft(
        originalText: 'tilápia grelhada',
        interpretedFoodText: 'tilápia',
        role: ComponentRole::PrimaryFood,
        preparations: [new Preparation(PreparationType::Grilled)],
    );
    interpretationDraftForM12Scenario('tilápia grelhada', [$tilapia]);

    expect($tilapia->quantity)->toBeNull()
        ->and($tilapia->preparations)->toHaveCount(1)
        ->and($tilapia->preparations[0]->type)->toBe(PreparationType::Grilled)
        ->and($tilapia->interpretedFoodText)->toBe('tilápia');
});

it('keeps tilapia and rice as separate components without a shared quantity', function () {
    $tilapia = new MealComponentDraft('tilápia', 'tilápia', ComponentRole::PrimaryFood);
    $rice = new MealComponentDraft('arroz', 'arroz', ComponentRole::Accompaniment);
    $draft = interpretationDraftForM12Scenario('tilápia com arroz', [$tilapia, $rice]);

    expect($draft->meal->entries[0]->components)->toHaveCount(2)
        ->and($tilapia->quantity)->toBeNull()
        ->and($rice->quantity)->toBeNull()
        ->and($tilapia->interpretedFoodText)->toBe('tilápia')
        ->and($rice->interpretedFoodText)->toBe('arroz');
});

it('keeps 350g as the entry total without distributing it to components', function () {
    $entryQuantity = new Quantity(
        type: QuantityType::EntryTotal,
        source: QuantitySource::UserReported,
        amount: 350,
        unit: 'g',
    );
    $components = [
        new MealComponentDraft('arroz', 'arroz', ComponentRole::Accompaniment),
        new MealComponentDraft('frango', 'frango', ComponentRole::PrimaryFood),
        new MealComponentDraft('legumes', 'legumes', ComponentRole::Accompaniment),
    ];
    $draft = interpretationDraftForM12Scenario(
        '350g de arroz, frango e legumes',
        $components,
        $entryQuantity,
    );
    $entry = $draft->meal->entries[0];

    expect($entry->quantity)->toBe($entryQuantity)
        ->and($entryQuantity->type)->toBe(QuantityType::EntryTotal)
        ->and($entryQuantity->amount)->toBe(350.0)
        ->and($entryQuantity->unit)->toBe('g')
        ->and($entryQuantity->gramAmount)->toBeNull()
        ->and(array_map(fn (MealComponentDraft $component) => $component->quantity, $components))
        ->toBe([null, null, null]);
});

it('keeps coffee and milk as separate beverage components', function () {
    $coffee = new MealComponentDraft('café', 'café', ComponentRole::BeverageComponent);
    $milk = new MealComponentDraft('leite', 'leite', ComponentRole::Ingredient);
    $draft = interpretationDraftForM12Scenario('café com leite', [$coffee, $milk]);

    expect($draft->meal->entries[0]->components)->toBe([$coffee, $milk])
        ->and($coffee->role)->toBe(ComponentRole::BeverageComponent)
        ->and($milk->role)->toBe(ComponentRole::Ingredient)
        ->and($coffee->quantity)->toBeNull()
        ->and($milk->quantity)->toBeNull();
});

it('keeps bread and cream cheese as separate components', function () {
    $bread = new MealComponentDraft('pão', 'pão', ComponentRole::PrimaryFood);
    $creamCheese = new MealComponentDraft('requeijão', 'requeijão', ComponentRole::Topping);
    $draft = interpretationDraftForM12Scenario('pão com requeijão', [$bread, $creamCheese]);

    expect($draft->meal->entries[0]->components)->toBe([$bread, $creamCheese])
        ->and($bread->role)->toBe(ComponentRole::PrimaryFood)
        ->and($creamCheese->role)->toBe(ComponentRole::Topping)
        ->and($bread->quantity)->toBeNull()
        ->and($creamCheese->quantity)->toBeNull();
});

it('keeps potato and mayonnaise as separate components', function () {
    $potato = new MealComponentDraft('batata', 'batata', ComponentRole::PrimaryFood);
    $mayonnaise = new MealComponentDraft('maionese', 'maionese', ComponentRole::Condiment);
    $draft = interpretationDraftForM12Scenario('batata com maionese', [$potato, $mayonnaise]);

    expect($draft->meal->entries[0]->components)->toBe([$potato, $mayonnaise])
        ->and($potato->role)->toBe(ComponentRole::PrimaryFood)
        ->and($mayonnaise->role)->toBe(ComponentRole::Condiment)
        ->and($potato->quantity)->toBeNull()
        ->and($mayonnaise->quantity)->toBeNull();
});

it('keeps a vague oil quantity without converting it to grams', function () {
    $quantity = new Quantity(
        type: QuantityType::Vague,
        source: QuantitySource::UserReported,
        measureText: 'um pouco',
    );
    $oil = new MealComponentDraft(
        originalText: 'um pouco de azeite',
        interpretedFoodText: 'azeite',
        role: ComponentRole::CookingFat,
        quantity: $quantity,
    );
    interpretationDraftForM12Scenario('um pouco de azeite', [$oil]);

    expect($quantity->type)->toBe(QuantityType::Vague)
        ->and($quantity->gramAmount)->toBeNull()
        ->and($oil->quantity)->toBe($quantity);
});

it('preserves a household measure without converting it to grams', function () {
    $quantity = new Quantity(
        type: QuantityType::HouseholdMeasure,
        source: QuantitySource::UserReported,
        amount: 2,
        unit: 'colher',
        measureText: 'duas colheres',
    );
    $rice = new MealComponentDraft(
        originalText: 'duas colheres de arroz',
        interpretedFoodText: 'arroz',
        role: ComponentRole::PrimaryFood,
        quantity: $quantity,
    );
    interpretationDraftForM12Scenario('duas colheres de arroz', [$rice]);

    expect($quantity->type)->toBe(QuantityType::HouseholdMeasure)
        ->and($quantity->amount)->toBe(2.0)
        ->and($quantity->measureText)->toBe('duas colheres')
        ->and($quantity->gramAmount)->toBeNull();
});

it('preserves a package fraction without assuming a package size', function () {
    $quantity = new Quantity(
        type: QuantityType::PackageFraction,
        source: QuantitySource::UserReported,
        packageFraction: 0.5,
    );
    $chips = new MealComponentDraft(
        originalText: 'meio pacote de chips',
        interpretedFoodText: 'chips',
        role: ComponentRole::PrimaryFood,
        quantity: $quantity,
    );
    interpretationDraftForM12Scenario('meio pacote de chips', [$chips]);

    expect($quantity->type)->toBe(QuantityType::PackageFraction)
        ->and($quantity->packageFraction)->toBe(0.5)
        ->and($quantity->amount)->toBeNull()
        ->and($quantity->unit)->toBeNull()
        ->and($quantity->gramAmount)->toBeNull();
});

it('preserves a sized unit without converting it to grams', function () {
    $quantity = new Quantity(
        type: QuantityType::SizedUnit,
        source: QuantitySource::UserReported,
        amount: 1,
        unit: 'pedaço',
        sizeText: 'médio',
    );
    $kibe = new MealComponentDraft(
        originalText: '1 pedaço médio de kibe',
        interpretedFoodText: 'kibe',
        role: ComponentRole::PrimaryFood,
        quantity: $quantity,
    );
    interpretationDraftForM12Scenario('1 pedaço médio de kibe', [$kibe]);

    expect($quantity->type)->toBe(QuantityType::SizedUnit)
        ->and($quantity->amount)->toBe(1.0)
        ->and($quantity->sizeText)->toBe('médio')
        ->and($quantity->gramAmount)->toBeNull();
});

it('rejects invalid exact quantity shapes', function (?float $amount, ?string $unit) {
    expect(fn () => new Quantity(
        type: QuantityType::Exact,
        source: QuantitySource::UserReported,
        amount: $amount,
        unit: $unit,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'missing amount' => [null, 'g'],
    'zero amount' => [0.0, 'g'],
    'negative amount' => [-1.0, 'g'],
    'non-finite amount' => [INF, 'g'],
    'missing unit' => [1.0, null],
    'blank unit' => [1.0, '   '],
]);

it('rejects invalid package fractions', function (?float $packageFraction) {
    expect(fn () => new Quantity(
        type: QuantityType::PackageFraction,
        source: QuantitySource::UserReported,
        packageFraction: $packageFraction,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'missing fraction' => null,
    'below zero' => -0.01,
    'above one' => 1.01,
    'non-finite fraction' => INF,
]);

it('accepts inclusive package fraction boundaries', function (float $packageFraction) {
    $quantity = new Quantity(
        type: QuantityType::PackageFraction,
        source: QuantitySource::UserReported,
        packageFraction: $packageFraction,
    );

    expect($quantity->packageFraction)->toBe($packageFraction);
})->with([0.0, 1.0]);

it('rejects an out-of-range package fraction on any quantity shape', function () {
    expect(fn () => new Quantity(
        type: QuantityType::Exact,
        source: QuantitySource::UserReported,
        amount: 1,
        unit: 'g',
        packageFraction: 1.01,
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects household measures without original measure text', function (?string $measureText) {
    expect(fn () => new Quantity(
        type: QuantityType::HouseholdMeasure,
        source: QuantitySource::UserReported,
        measureText: $measureText,
    ))->toThrow(InvalidArgumentException::class);
})->with([null, '', '   ']);

it('rejects sized units without size text', function (?string $sizeText) {
    expect(fn () => new Quantity(
        type: QuantityType::SizedUnit,
        source: QuantitySource::UserReported,
        sizeText: $sizeText,
    ))->toThrow(InvalidArgumentException::class);
})->with([null, '', '   ']);

it('rejects structured payloads for a vague quantity', function (
    ?float $amount,
    ?string $unit,
    ?float $gramAmount,
    ?float $packageFraction,
    ?string $sizeText,
) {
    expect(fn () => new Quantity(
        type: QuantityType::Vague,
        source: QuantitySource::DeterministicallyConverted,
        amount: $amount,
        unit: $unit,
        gramAmount: $gramAmount,
        packageFraction: $packageFraction,
        sizeText: $sizeText,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'gram amount' => [null, null, 10.0, null, null],
    'gram unit amount' => [120.0, 'g', null, null, null],
    'numeric amount' => [1.0, null, null, null, null],
    'unit' => [null, 'colher', null, null, null],
    'package fraction' => [null, null, null, 0.5, null],
    'size' => [null, null, null, null, 'médio'],
]);

it('accepts a vague quantity with only its original text', function () {
    $quantity = new Quantity(
        type: QuantityType::Vague,
        source: QuantitySource::UserReported,
        measureText: 'uma porção pequena',
    );

    expect($quantity->measureText)->toBe('uma porção pequena')
        ->and($quantity->amount)->toBeNull()
        ->and($quantity->unit)->toBeNull()
        ->and($quantity->gramAmount)->toBeNull()
        ->and($quantity->packageFraction)->toBeNull()
        ->and($quantity->sizeText)->toBeNull();
});

it('rejects non-positive and non-finite gram amounts', function (float $gramAmount) {
    expect(fn () => new Quantity(
        type: QuantityType::Exact,
        source: QuantitySource::UserReported,
        amount: 1,
        unit: 'g',
        gramAmount: $gramAmount,
    ))->toThrow(InvalidArgumentException::class);
})->with([0.0, -1.0, INF]);

it('rejects gram amounts that are neither exact nor deterministically converted', function (
    QuantityType $type,
    ?string $measureText,
    ?string $sizeText,
    ?float $packageFraction,
) {
    expect(fn () => new Quantity(
        type: $type,
        source: QuantitySource::UserReported,
        gramAmount: 50,
        measureText: $measureText,
        sizeText: $sizeText,
        packageFraction: $packageFraction,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'household measure' => [QuantityType::HouseholdMeasure, 'duas colheres', null, null],
    'sized unit' => [QuantityType::SizedUnit, null, 'médio', null],
    'package fraction' => [QuantityType::PackageFraction, null, null, 0.5],
    'entry total' => [QuantityType::EntryTotal, null, null, null],
]);

it('allows an explicit deterministic conversion to grams', function () {
    $quantity = new Quantity(
        type: QuantityType::HouseholdMeasure,
        source: QuantitySource::DeterministicallyConverted,
        amount: 2,
        unit: 'colher',
        gramAmount: 50,
        measureText: 'duas colheres',
    );

    expect($quantity->gramAmount)->toBe(50.0)
        ->and($quantity->source)->toBe(QuantitySource::DeterministicallyConverted);
});

it('rejects an entry-total quantity owned by a component', function () {
    $entryTotal = new Quantity(
        type: QuantityType::EntryTotal,
        source: QuantitySource::UserReported,
        amount: 350,
        unit: 'g',
    );

    expect(fn () => new MealComponentDraft(
        originalText: 'arroz',
        interpretedFoodText: 'arroz',
        role: ComponentRole::Accompaniment,
        quantity: $entryTotal,
    ))->toThrow(InvalidArgumentException::class);
});
