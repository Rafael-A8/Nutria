<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Middleware\Guardrails;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

it('starts a new internal conversation when the current cycle history has incomplete tool output', function () {
    NutritionistAgent::fake(['Resposta recuperada.']);

    $this->app->bind(Guardrails::class, fn () => new class
    {
        public function handle(AgentPrompt $prompt, Closure $next): mixed
        {
            return $next($prompt);
        }
    });

    $user = User::factory()->create();

    try {
        Carbon::setTestNow(Carbon::parse('2026-06-18 19:55:00', config('app.timezone')));

        $brokenConversationId = (string) Str::uuid7();
        $now = now();

        DB::table('agent_conversations')->insert([
            'id' => $brokenConversationId,
            'user_id' => $user->id,
            'title' => 'Daily meals',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $brokenConversationId,
            'user_id' => $user->id,
            'agent' => NutritionistAgent::class,
            'role' => 'assistant',
            'content' => '',
            'attachments' => '[]',
            'tool_calls' => json_encode([
                [
                    'id' => 'fc_missing',
                    'name' => 'EstimateMealTool',
                    'arguments' => ['meal_type' => 'lanche'],
                    'result_id' => 'call_missing',
                    'reasoning_id' => null,
                    'reasoning_summary' => null,
                ],
            ]),
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $newConversationId = $this
            ->actingAs($user)
            ->postJson('/chat/send', ['message' => 'Pode continuar?'])
            ->assertSuccessful()
            ->json('conversationId');

        expect($newConversationId)
            ->toBeString()
            ->not->toBe($brokenConversationId);

        expect(DB::table('agent_conversations')->where('user_id', $user->id)->count())->toBe(2)
            ->and($user->chatMessages()->where('role', 'user')->count())->toBe(1)
            ->and($user->chatMessages()->where('role', 'assistant')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});
