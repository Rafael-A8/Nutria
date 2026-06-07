<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Middleware\Guardrails;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;

it('uses one internal agent conversation per current cycle while keeping visible chat history', function () {
    NutritionistAgent::fake([
        'Primeira resposta da semana.',
        'Segunda resposta da mesma semana.',
        'Resposta da nova semana.',
    ]);

    AnonymousAgent::fake([
        'Previous cycle summary generated from user messages.',
    ]);

    $this->app->bind(Guardrails::class, fn () => new class
    {
        public function handle(AgentPrompt $prompt, Closure $next): mixed
        {
            return $next($prompt);
        }
    });

    $user = User::factory()->create();

    try {
        Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00', config('app.timezone')));

        $firstConversationId = $this
            ->actingAs($user)
            ->postJson('/chat/send', ['message' => 'Primeira mensagem da semana.'])
            ->assertSuccessful()
            ->json('conversationId');

        Carbon::setTestNow(Carbon::parse('2026-06-10 10:00:00', config('app.timezone')));

        $secondConversationId = $this
            ->actingAs($user)
            ->postJson('/chat/send', ['message' => 'Segunda mensagem da mesma semana.'])
            ->assertSuccessful()
            ->json('conversationId');

        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00', config('app.timezone')));

        $thirdConversationId = $this
            ->actingAs($user)
            ->postJson('/chat/send', ['message' => 'Primeira mensagem da nova semana.'])
            ->assertSuccessful()
            ->json('conversationId');

        expect($firstConversationId)
            ->toBeString()
            ->and($secondConversationId)->toBe($firstConversationId)
            ->and($thirdConversationId)->toBeString()
            ->and($thirdConversationId)->not->toBe($firstConversationId);

        expect(DB::table('agent_conversations')->where('user_id', $user->id)->count())->toBe(2)
            ->and($user->chatMessages()->where('role', 'user')->count())->toBe(3)
            ->and($user->chatMessages()->where('role', 'assistant')->count())->toBe(3)
            ->and($user->chatMessages()->count())->toBe(6);

        $firstCycleUserMessages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $firstConversationId)
            ->where('role', 'user')
            ->orderBy('created_at')
            ->pluck('content')
            ->all();

        $secondCycleUserMessages = DB::table('agent_conversation_messages')
            ->where('conversation_id', $thirdConversationId)
            ->where('role', 'user')
            ->orderBy('created_at')
            ->pluck('content')
            ->all();

        expect($firstCycleUserMessages)->toBe([
            'Primeira mensagem da semana.',
            'Segunda mensagem da mesma semana.',
        ])->and($secondCycleUserMessages)->toBe([
            'Primeira mensagem da nova semana.',
        ]);

        expect($user->conversationSummaries()->first())
            ->message_count->toBe(2)
            ->summary->toBe('Previous cycle summary generated from user messages.');
    } finally {
        Carbon::setTestNow();
    }
});
