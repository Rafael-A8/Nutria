<?php

use App\Models\User;
use App\Services\MealService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new MealService;
});

it('registers a meal', function () {
    $meal = $this->service->registerMeal($this->user, 'almoco');

    expect($meal)
        ->user_id->toBe($this->user->id)
        ->meal_type->toBe('almoco');

    $this->assertDatabaseHas('meals', [
        'id' => $meal->id,
        'user_id' => $this->user->id,
        'meal_type' => 'almoco',
    ]);
});

it('registers a meal with custom consumed_at', function () {
    $date = Carbon::parse('2026-03-15 12:00:00');
    $meal = $this->service->registerMeal($this->user, 'jantar', $date);

    expect($meal->consumed_at->toDateTimeString())->toBe('2026-03-15 12:00:00');
});

it('adds an item to a meal', function () {
    $meal = $this->service->registerMeal($this->user, 'almoco');
    $item = $this->service->addItem($meal, 'arroz', 150, 195);

    expect($item)
        ->meal_id->toBe($meal->id)
        ->description->toBe('arroz')
        ->quantity_grams->toBe(150)
        ->calories->toBe(195);

    $this->assertDatabaseHas('meal_items', [
        'id' => $item->id,
        'meal_id' => $meal->id,
        'description' => 'arroz',
    ]);
});

it('adds an item without quantity_grams', function () {
    $meal = $this->service->registerMeal($this->user, 'lanche');
    $item = $this->service->addItem($meal, 'coxinha', null, 300);

    expect($item->quantity_grams)->toBeNull();
});

it('gets today summary', function () {
    $meal = $this->service->registerMeal($this->user, 'almoco');
    $this->service->addItem($meal, 'arroz', 150, 195);
    $this->service->addItem($meal, 'feijão', 100, 77);

    $meal2 = $this->service->registerMeal($this->user, 'lanche');
    $this->service->addItem($meal2, 'coxinha', null, 300);

    $summary = $this->service->getTodaySummary($this->user);

    expect($summary)
        ->total_calories->toBe(572)
        ->meal_count->toBe(2)
        ->meals->toHaveCount(2);
});

it('does not include other users meals in summary', function () {
    $otherUser = User::factory()->create();
    $meal = $this->service->registerMeal($otherUser, 'almoco');
    $this->service->addItem($meal, 'arroz', 150, 195);

    $summary = $this->service->getTodaySummary($this->user);

    expect($summary['total_calories'])->toBe(0);
});

it('gets week summary', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-02 12:00:00'));

    $yesterday = Carbon::parse('2026-04-01 12:00:00');
    $meal = $this->service->registerMeal($this->user, 'almoco', $yesterday);
    $this->service->addItem($meal, 'arroz', 150, 200);

    $today = Carbon::parse('2026-04-02 12:00:00');
    $meal2 = $this->service->registerMeal($this->user, 'jantar', $today);
    $this->service->addItem($meal2, 'pizza', null, 800);

    $weekSummary = $this->service->getWeekSummary($this->user);

    expect($weekSummary)
        ->toHaveKey('2026-04-01', 200)
        ->toHaveKey('2026-04-02', 800);

    Carbon::setTestNow();
});
