<?php

use App\Ai\Tools\RegisterMealTool;
use App\Models\User;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

it('registers a meal from plain text item lines', function () {
    Embeddings::fake();

    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    $result = $tool->handle(new Request([
        'meal_type' => 'jantar',
        'consumed_at' => '2026-06-13 20:00:00',
        'items' => "description=arroz; quantity_grams=130; calories=166\ndescription=feijao; quantity_grams=100; calories=76",
        'expected_items_count' => 2,
        'pending_items_count' => 0,
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'registered',
            'meal_type' => 'jantar',
            'consumed_at' => '2026-06-13 20:00:00',
            'item_count' => 2,
            'total_calories' => 242,
        ]);

    $meal = $user->meals()->with('items')->first();

    expect($meal)->not->toBeNull();
    expect($meal->consumed_at->toDateTimeString())->toBe('2026-06-13 20:00:00');
    expect($meal->items)->toHaveCount(2);
    expect($meal->items->pluck('description')->all())->toBe(['arroz', 'feijao']);
});

it('blocks registration when consumed_at is missing', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    $result = $tool->handle(new Request([
        'meal_type' => 'jantar',
        'items' => 'description=arroz; quantity_grams=130; calories=166',
        'expected_items_count' => 1,
        'pending_items_count' => 0,
    ]));

    expect(json_decode($result, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'status' => 'registration_blocked',
            'item_count' => 1,
        ]);

    expect($user->meals()->count())->toBe(0);
});

it('blocks partial registration while pending items exist', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    $result = $tool->handle(new Request([
        'meal_type' => 'almoco',
        'consumed_at' => '2026-06-14 12:30:00',
        'items' => 'description=arroz; quantity_grams=120; calories=154',
        'expected_items_count' => 3,
        'pending_items_count' => 2,
    ]));

    $payload = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('registration_blocked')
        ->and($payload['blocking_reasons'])->toContain('There are 2 pending item(s). Resolve every extracted item before registration.')
        ->and($payload['blocking_reasons'])->toContain('Registration item count mismatch. Expected 3, received 1.');

    expect($user->meals()->count())->toBe(0);
});

it('blocks registration when an item has no positive calorie estimate', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    $result = $tool->handle(new Request([
        'meal_type' => 'lanche',
        'consumed_at' => '2026-06-14 16:00:00',
        'items' => 'description=suco verde; quantity_grams=300; calories=',
        'expected_items_count' => 1,
        'pending_items_count' => 0,
    ]));

    $payload = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

    expect($payload['status'])->toBe('registration_blocked')
        ->and($payload['blocking_reasons'])->toContain('Item 1 is missing a positive calorie estimate.');

    expect($user->meals()->count())->toBe(0);
});
