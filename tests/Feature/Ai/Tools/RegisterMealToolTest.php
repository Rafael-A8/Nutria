<?php

use App\Ai\Tools\RegisterMealTool;
use App\Models\User;
use Laravel\Ai\Tools\Request;

it('registers a meal from plain text item lines', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    $result = $tool->handle(new Request([
        'meal_type' => 'jantar',
        'items' => "description=arroz; quantity_grams=130; calories=166\ndescription=feijao; quantity_grams=100; calories=76",
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'registered',
            'meal_type' => 'jantar',
            'item_count' => 2,
            'total_calories' => 242,
        ]);

    $meal = $user->meals()->with('items')->first();

    expect($meal)->not->toBeNull();
    expect($meal->items)->toHaveCount(2);
    expect($meal->items->pluck('description')->all())->toBe(['arroz', 'feijao']);
});
