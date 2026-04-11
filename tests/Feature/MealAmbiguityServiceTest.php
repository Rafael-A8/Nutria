<?php

use App\Services\MealAmbiguityService;

it('returns low confidence instead of clarification for unknown items without vague quantity', function () {
    $service = new MealAmbiguityService;

    $result = $service->assess(
        description: 'kombucha',
        quantityGrams: 300,
        hasReference: false,
        hasHistory: false,
    );

    expect($result['requires_clarification'])->toBeFalse()
        ->and($result['is_low_confidence'])->toBeTrue();
});

it('still requires clarification for unknown items with vague quantity', function () {
    $service = new MealAmbiguityService;

    $result = $service->assess(
        description: 'um pouco de kombucha',
        hasReference: false,
        hasHistory: false,
    );

    expect($result['requires_clarification'])->toBeTrue()
        ->and($result['is_low_confidence'])->toBeFalse();
});

it('does not flag low confidence when item has reference', function () {
    $service = new MealAmbiguityService;

    $result = $service->assess(
        description: 'arroz',
        quantityGrams: 120,
        hasReference: true,
        hasHistory: false,
    );

    expect($result['requires_clarification'])->toBeFalse()
        ->and($result['is_low_confidence'])->toBeFalse();
});

it('still requires clarification for high-impact cooking fat in preparation', function () {
    $service = new MealAmbiguityService;

    $result = $service->assess(
        description: 'manteiga',
        quantityGrams: 25,
        context: 'usada no preparo do frango',
        isCookingFat: true,
        hasReference: true,
        hasHistory: false,
    );

    expect($result['requires_clarification'])->toBeTrue()
        ->and($result['is_low_confidence'])->toBeFalse()
        ->and($result['treat_as_preparation_only'])->toBeTrue();
});
