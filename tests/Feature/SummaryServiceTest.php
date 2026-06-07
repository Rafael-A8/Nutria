<?php

use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use App\Models\User;
use App\Services\ChatMessageService;
use App\Services\MealService;
use App\Services\SummaryService;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
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

it('uses the new conversation summary table only', function () {
    expect(Schema::hasTable('summaries'))->toBeFalse()
        ->and(Schema::hasTable('user_conversation_summaries'))->toBeTrue();
});

it('defines supported conversation summary trigger types', function () {
    expect(ConversationSummaryTriggerType::values())->toBe([
        'weekly',
        'biweekly',
        'monthly',
        'message_limit',
        'token_limit',
        'manual',
        'billing_limit',
    ]);
});

it('generates summary for previous conversation cycle', function () {
    Embeddings::fake();
    AnonymousAgent::fake(['Weekly summary generated.']);

    Carbon::setTestNow(Carbon::parse('2026-06-03 20:30:00'));
    $this->chatMessageService->storeUserMessage($this->user, 'Hoje fui em um aniversário e comi mais do que planejava.');
    $this->chatMessageService->storeAssistantMessage($this->user, 'Resposta do agente que não deve entrar no resumo.');

    $meal = $this->mealService->registerMeal($this->user, 'almoco', Carbon::parse('2026-06-03 12:00:00'));
    $this->mealService->addItem($meal, 'arroz', 150, 200);

    $this->weightLogService->log($this->user, 75.0, Carbon::parse('2026-06-04'));

    Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));

    $summary = $this->service->generateConversationCycleSummaryIfNeeded($this->user);

    expect($summary)
        ->not->toBeNull()
        ->summary_type->toBe(ConversationSummaryType::ConversationCycle)
        ->trigger_type->toBe(ConversationSummaryTriggerType::Weekly)
        ->period_start->toDateTimeString()->toBe('2026-06-01 00:00:00')
        ->period_end->toDateTimeString()->toBe('2026-06-07 23:59:59')
        ->summary->toBe('Weekly summary generated.')
        ->message_count->toBe(1)
        ->stats->toBeArray();

    expect($summary->stats['meals']['total_calories'])->toBe(200);
    expect($summary->stats['weights']['entries'])->toBe(1);
    expect($summary->stats['conversation'])->toBe([
        'message_count' => 1,
        'selected_message_count' => 1,
    ]);

    $this->assertDatabaseHas('user_conversation_summaries', [
        'user_id' => $this->user->id,
        'summary_type' => ConversationSummaryType::ConversationCycle->value,
        'trigger_type' => ConversationSummaryTriggerType::Weekly->value,
        'summary' => 'Weekly summary generated.',
        'message_count' => 1,
    ]);

    $this->assertDatabaseMissing('chat_messages', [
        'user_id' => $this->user->id,
        'role' => 'assistant',
        'content' => 'Weekly summary generated.',
    ]);

    AnonymousAgent::assertPrompted(fn ($prompt): bool => str_contains(
        $prompt->prompt,
        'Conversation cycle from 2026-06-01 to 2026-06-07:'
    ) && str_contains(
        $prompt->prompt,
        '- User conversation signals:'
    ) && str_contains(
        $prompt->prompt,
        'Hoje fui em um aniversário'
    ) && ! str_contains(
        $prompt->prompt,
        'Resposta do agente que não deve entrar no resumo.'
    ));

    Carbon::setTestNow();
});

it('returns null when summary already exists', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));

    $this->user->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Weekly,
        'period_start' => Carbon::parse('2026-06-01 00:00:00'),
        'period_end' => Carbon::parse('2026-06-07 23:59:59'),
        'summary' => 'Já existia.',
        'stats' => [],
    ]);

    $result = $this->service->generateConversationCycleSummaryIfNeeded($this->user);

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

it('returns null when previous conversation cycle has no meals or user messages', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));

    $result = $this->service->generateConversationCycleSummaryIfNeeded($this->user);

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

it('generates summary for previous conversation cycle with user messages even without meals', function () {
    AnonymousAgent::fake(['Message-only cycle summary.']);

    Carbon::setTestNow(Carbon::parse('2026-06-05 08:00:00'));
    $this->chatMessageService->storeUserMessage($this->user, 'Não consegui registrar refeições, mas tive dificuldade com beliscos à noite.');

    Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00'));

    $summary = $this->service->generateConversationCycleSummaryIfNeeded($this->user);

    expect($summary)
        ->not->toBeNull()
        ->summary->toBe('Message-only cycle summary.')
        ->message_count->toBe(1);

    expect($summary->stats['meals']['total_meals'])->toBe(0)
        ->and($summary->stats['conversation'])->toBe([
            'message_count' => 1,
            'selected_message_count' => 1,
        ]);

    $this->assertDatabaseMissing('chat_messages', [
        'user_id' => $this->user->id,
        'role' => 'assistant',
        'content' => 'Message-only cycle summary.',
    ]);

    AnonymousAgent::assertPrompted(fn ($prompt): bool => str_contains(
        $prompt->prompt,
        'Não consegui registrar refeições'
    ));

    Carbon::setTestNow();
});

it('gets recent conversation summaries ordered by most recent cycle first', function () {
    $this->user->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Weekly,
        'period_start' => Carbon::parse('2026-01-05'),
        'period_end' => Carbon::parse('2026-01-11 23:59:59'),
        'summary' => 'Primeiro ciclo',
        'stats' => [],
    ]);

    $this->user->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Weekly,
        'period_start' => Carbon::parse('2026-02-02'),
        'period_end' => Carbon::parse('2026-02-08 23:59:59'),
        'summary' => 'Segundo ciclo',
        'stats' => [],
    ]);

    $this->user->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Weekly,
        'period_start' => Carbon::parse('2026-03-02'),
        'period_end' => Carbon::parse('2026-03-08 23:59:59'),
        'summary' => 'Terceiro ciclo',
        'stats' => [],
    ]);

    $summaries = $this->service->getRecentConversationSummaries($this->user, 2);

    expect($summaries)->toHaveCount(2);
    expect($summaries[0]->summary)->toBe('Terceiro ciclo');
    expect($summaries[1]->summary)->toBe('Segundo ciclo');
});

it('does not include summaries from other users', function () {
    $otherUser = User::factory()->create();

    $otherUser->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Weekly,
        'period_start' => Carbon::parse('2026-03-02'),
        'period_end' => Carbon::parse('2026-03-08 23:59:59'),
        'summary' => 'Outro usuário',
        'stats' => [],
    ]);

    $summaries = $this->service->getRecentConversationSummaries($this->user);

    expect($summaries)->toBeEmpty();
});

it('accepts explicit summary type enum when loading recent summaries', function () {
    $this->user->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Manual,
        'period_start' => Carbon::parse('2026-03-02'),
        'period_end' => Carbon::parse('2026-03-08 23:59:59'),
        'summary' => 'Manual summary',
        'stats' => [],
    ]);

    $summaries = $this->service->getRecentConversationSummaries(
        $this->user,
        summaryType: ConversationSummaryType::ConversationCycle,
    );

    expect($summaries)->toHaveCount(1);
});
