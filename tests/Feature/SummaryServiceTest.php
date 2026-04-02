<?php

use App\Models\User;
use App\Services\ChatMessageService;
use App\Services\MealService;
use App\Services\SummaryService;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->mealService = new MealService;
    $this->weightLogService = new WeightLogService;
    $this->chatMessageService = new ChatMessageService;
    $this->service = new SummaryService(
        $this->mealService,
        $this->weightLogService,
        $this->chatMessageService,
    );
});

it('generates summary for previous month', function () {
    Embeddings::fake();
    AnonymousAgent::fake(['Resumo do mês gerado pelo Morgan.']);

    Carbon::setTestNow(Carbon::parse('2026-04-02 10:00:00'));

    $meal = $this->mealService->registerMeal($this->user, 'almoco', Carbon::parse('2026-03-15 12:00:00'));
    $this->mealService->addItem($meal, 'arroz', 150, 200);

    $this->weightLogService->log($this->user, 75.0, Carbon::parse('2026-03-10'));

    $summary = $this->service->generateIfNeeded($this->user);

    expect($summary)
        ->not->toBeNull()
        ->month->toBe(3)
        ->year->toBe(2026)
        ->summary->toBe('Resumo do mês gerado pelo Morgan.')
        ->stats->toBeArray();

    expect($summary->stats['meals']['total_calories'])->toBe(200);
    expect($summary->stats['weights']['entries'])->toBe(1);

    $this->assertDatabaseHas('summaries', [
        'user_id' => $this->user->id,
        'month' => 3,
        'year' => 2026,
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $this->user->id,
        'role' => 'assistant',
        'content' => 'Resumo do mês gerado pelo Morgan.',
    ]);

    AnonymousAgent::assertPrompted(fn ($prompt) => true);

    Carbon::setTestNow();
});

it('returns null when summary already exists', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-02 10:00:00'));

    $this->user->summaries()->create([
        'month' => 3,
        'year' => 2026,
        'summary' => 'Já existia.',
        'stats' => [],
    ]);

    $result = $this->service->generateIfNeeded($this->user);

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

it('returns null when previous month has no meals', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-02 10:00:00'));

    $result = $this->service->generateIfNeeded($this->user);

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

it('gets recent summaries ordered by most recent first', function () {
    $this->user->summaries()->create([
        'month' => 1,
        'year' => 2026,
        'summary' => 'Janeiro',
        'stats' => [],
    ]);

    $this->user->summaries()->create([
        'month' => 2,
        'year' => 2026,
        'summary' => 'Fevereiro',
        'stats' => [],
    ]);

    $this->user->summaries()->create([
        'month' => 3,
        'year' => 2026,
        'summary' => 'Março',
        'stats' => [],
    ]);

    $summaries = $this->service->getRecentSummaries($this->user, 2);

    expect($summaries)->toHaveCount(2);
    expect($summaries[0]->summary)->toBe('Março');
    expect($summaries[1]->summary)->toBe('Fevereiro');
});

it('does not include summaries from other users', function () {
    $otherUser = User::factory()->create();

    $otherUser->summaries()->create([
        'month' => 3,
        'year' => 2026,
        'summary' => 'Outro usuário',
        'stats' => [],
    ]);

    $summaries = $this->service->getRecentSummaries($this->user);

    expect($summaries)->toBeEmpty();
});
