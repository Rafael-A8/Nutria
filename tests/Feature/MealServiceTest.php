<?php

use App\Models\User;
use App\Services\MealService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

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

it('adds an item to a meal and generates embedding', function () {
    Embeddings::fake();

    $meal = $this->service->registerMeal($this->user, 'almoco');
    $item = $this->service->addItem($meal, 'arroz', 150, 195);

    expect($item)
        ->meal_id->toBe($meal->id)
        ->description->toBe('arroz')
        ->quantity_grams->toBe(150)
        ->calories->toBe(195)
        ->embedding->toBeArray();

    $this->assertDatabaseHas('meal_items', [
        'id' => $item->id,
        'meal_id' => $meal->id,
        'description' => 'arroz',
    ]);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('arroz'));
});

it('adds an item without quantity_grams', function () {
    Embeddings::fake();

    $meal = $this->service->registerMeal($this->user, 'lanche');
    $item = $this->service->addItem($meal, 'coxinha', null, 300);

    expect($item->quantity_grams)->toBeNull();
});

it('finds similar items by description', function () {
    Embeddings::fake();

    $meal = $this->service->registerMeal($this->user, 'almoco');
    $this->service->addItem($meal, 'arroz branco', 150, 195);
    $this->service->addItem($meal, 'feijão preto', 100, 77);

    $similar = $this->service->findSimilarItems('arroz');

    expect($similar)->toBeInstanceOf(Collection::class);
});

it('returns empty collection when no similar items exist', function () {
    $similar = $this->service->findSimilarItems('pizza');

    expect($similar)->toBeEmpty();
});

it('gets today summary', function () {
    Embeddings::fake();

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
    Embeddings::fake();

    $otherUser = User::factory()->create();
    $meal = $this->service->registerMeal($otherUser, 'almoco');
    $this->service->addItem($meal, 'arroz', 150, 195);

    $summary = $this->service->getTodaySummary($this->user);

    expect($summary['total_calories'])->toBe(0);
});

it('gets week summary', function () {
    Embeddings::fake();

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

it('gets period summary', function () {
    Embeddings::fake();

    $startDate = Carbon::parse('2026-03-01');
    $endDate = Carbon::parse('2026-03-31');

    $meal1 = $this->service->registerMeal($this->user, 'almoco', Carbon::parse('2026-03-10 12:00:00'));
    $this->service->addItem($meal1, 'arroz', 150, 200);
    $this->service->addItem($meal1, 'feijão', 100, 80);

    $meal2 = $this->service->registerMeal($this->user, 'jantar', Carbon::parse('2026-03-10 19:00:00'));
    $this->service->addItem($meal2, 'arroz', 150, 200);

    $meal3 = $this->service->registerMeal($this->user, 'almoco', Carbon::parse('2026-03-15 12:00:00'));
    $this->service->addItem($meal3, 'pizza', null, 800);

    $summary = $this->service->getPeriodSummary($this->user, $startDate, $endDate);

    expect($summary)
        ->total_calories->toBe(1280)
        ->avg_daily_calories->toBe(640)
        ->total_meals->toBe(3)
        ->total_items->toBe(4)
        ->days_tracked->toBe(2)
        ->top_items->toHaveCount(3);

    expect($summary['top_items'][0])
        ->description->toBe('arroz')
        ->count->toBe(2)
        ->avg_calories->toBe(200);
});

it('returns zero period summary when no meals exist', function () {
    $summary = $this->service->getPeriodSummary(
        $this->user,
        Carbon::parse('2026-03-01'),
        Carbon::parse('2026-03-31'),
    );

    expect($summary)
        ->total_calories->toBe(0)
        ->avg_daily_calories->toBe(0)
        ->total_meals->toBe(0)
        ->total_items->toBe(0)
        ->days_tracked->toBe(0)
        ->top_items->toBeEmpty();
});
