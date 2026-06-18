<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Tools\RegisterMealTool;
use App\Enums\AiModel;
use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use App\Enums\UserMemoryCategory;
use App\Models\User;
use App\Models\UserMemory;
use App\Services\ChatMessageService;
use App\Services\MealService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

it('can be faked and responds to prompts', function () {
    NutritionistAgent::fake(['Olá! Como posso ajudar?']);

    $user = User::factory()->create();
    $response = (new NutritionistAgent($user))->prompt('Oi');

    expect($response->text)->toBe('Olá! Como posso ajudar?');

    NutritionistAgent::assertPrompted('Oi');
});

it('can be faked and never prompted', function () {
    NutritionistAgent::fake();

    NutritionistAgent::assertNeverPrompted();
});

it('injects relevant user memories into the current prompt', function () {
    $queryVector = embeddingVectorAt(0);

    Embeddings::fake(fn () => [$queryVector]);

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Comportamento->value,
        'content' => 'Usuária tem dificuldade com compulsão alimentar',
        'embedding' => $queryVector,
    ]);

    UserMemory::create([
        'user_id' => $otherUser->id,
        'category' => UserMemoryCategory::Preferencias->value,
        'content' => 'Outra usuária gosta de bolo de chocolate',
        'embedding' => $queryVector,
    ]);

    $processedPrompt = '';

    NutritionistAgent::fake(function (string $prompt) use (&$processedPrompt) {
        $processedPrompt = $prompt;

        return 'Resposta contextualizada';
    });

    $response = (new NutritionistAgent($user))->prompt("I'm craving cake today");

    expect($response->text)->toBe('Resposta contextualizada')
        ->and($processedPrompt)->toContain("I'm craving cake today")
        ->and($processedPrompt)->toContain('USER MEMORIES')
        ->and($processedPrompt)->toContain('CONTEXTUAL PREFERENCES AND BEHAVIOR')
        ->and($processedPrompt)->toContain('- [comportamento] Usuária tem dificuldade com compulsão alimentar')
        ->and($processedPrompt)->toContain('Use [preferencias] and [comportamento] naturally only when relevant.')
        ->and($processedPrompt)->toContain('Never mention memory retrieval.')
        ->and($processedPrompt)->toContain('Never expose internal memory mechanisms.')
        ->and($processedPrompt)->not->toContain('Outra usuária gosta de bolo de chocolate');
});

it('continues normally when no relevant memories are found', function () {
    Embeddings::fake(fn () => [embeddingVectorAt(0)]);

    $user = User::factory()->create();

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Preferencias->value,
        'content' => 'Usuária prefere cafés da manhã salgados',
        'embedding' => embeddingVectorAt(1),
    ]);

    $processedPrompt = '';

    NutritionistAgent::fake(function (string $prompt) use (&$processedPrompt) {
        $processedPrompt = $prompt;

        return 'Fluxo sem memória relevante';
    });

    $response = (new NutritionistAgent($user))->prompt('Quero um bolo hoje');

    expect($response->text)->toBe('Fluxo sem memória relevante')
        ->and($processedPrompt)->toBe('Quero um bolo hoje')
        ->and($processedPrompt)->not->toContain('USER MEMORIES');
});

it('limits injected memories and preserves conversation continuation history', function () {
    $queryVector = embeddingVectorAt(0);

    Embeddings::fake(fn () => [$queryVector]);

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    foreach (range(1, 5) as $index) {
        UserMemory::create([
            'user_id' => $user->id,
            'category' => UserMemoryCategory::Comportamento->value,
            'content' => "Memória relevante {$index}",
            'embedding' => $queryVector,
        ]);
    }

    UserMemory::create([
        'user_id' => $otherUser->id,
        'category' => UserMemoryCategory::Comportamento->value,
        'content' => 'Memória relevante de outra usuária',
        'embedding' => $queryVector,
    ]);

    $processedPrompts = [];

    NutritionistAgent::fake(function (string $prompt) use (&$processedPrompts) {
        $processedPrompts[] = $prompt;

        return count($processedPrompts) === 1
            ? 'Primeira resposta'
            : 'Segunda resposta';
    });

    $firstResponse = (new NutritionistAgent($user))
        ->forUser($user)
        ->prompt('Estou com vontade de bolo hoje');

    $secondResponse = (new NutritionistAgent($user))
        ->continue($firstResponse->conversationId, as: $user)
        ->prompt('Ainda estou pensando em bolo');

    $storedUserMessages = DB::table('agent_conversation_messages')
        ->where('conversation_id', $firstResponse->conversationId)
        ->where('role', 'user')
        ->orderBy('created_at')
        ->pluck('content')
        ->all();

    $memoryPrompts = array_values(array_filter(
        $processedPrompts,
        fn (string $prompt): bool => str_contains($prompt, 'USER MEMORIES')
    ));

    expect($secondResponse->conversationId)->toBe($firstResponse->conversationId)
        ->and($memoryPrompts)->toHaveCount(2)
        ->and(substr_count($memoryPrompts[0], '- [comportamento]'))->toBe(4)
        ->and(substr_count($memoryPrompts[1], '- [comportamento]'))->toBe(4)
        ->and($memoryPrompts[0])->not->toContain('Memória relevante de outra usuária')
        ->and($memoryPrompts[1])->not->toContain('Memória relevante de outra usuária')
        ->and($storedUserMessages)->toBe([
            'Estou com vontade de bolo hoje',
            'Ainda estou pensando em bolo',
        ]);
});

it('injects priority memories by category even when semantic search does not match them', function () {
    Embeddings::fake(fn () => [embeddingVectorAt(0)]);

    $user = User::factory()->create();

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Restricoes->value,
        'content' => 'Usuária não pode consumir lactose',
        'embedding' => embeddingVectorAt(1),
    ]);

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Objetivos->value,
        'content' => 'Usuária quer manter consistência semanal',
        'embedding' => embeddingVectorAt(2),
    ]);

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Preferencias->value,
        'content' => 'Usuária gosta de refeições salgadas',
        'embedding' => embeddingVectorAt(3),
    ]);

    $processedPrompt = '';

    NutritionistAgent::fake(function (string $prompt) use (&$processedPrompt) {
        $processedPrompt = $prompt;

        return 'Resposta com memória prioritária';
    });

    $response = (new NutritionistAgent($user))->prompt('Quero organizar minha semana');

    expect($response->text)->toBe('Resposta com memória prioritária')
        ->and($processedPrompt)->toContain('HARD SAFETY RESTRICTIONS')
        ->and($processedPrompt)->toContain('ACTIVE GOALS')
        ->and($processedPrompt)->toContain('- [restricoes] Usuária não pode consumir lactose')
        ->and($processedPrompt)->toContain('- [objetivos] Usuária quer manter consistência semanal')
        ->and($processedPrompt)->toContain('Treat [restricoes] as hard safety constraints.')
        ->and($processedPrompt)->toContain('Treat [objetivos] as active guidance.')
        ->and($processedPrompt)->toContain('Do not mention unrelated memories just because they are present.')
        ->and($processedPrompt)->not->toContain('Usuária gosta de refeições salgadas');
});

it('instructs the agent to connect relevant restrictions and goals without dumping unrelated memories', function () {
    Embeddings::fake(fn () => [embeddingVectorAt(0)]);

    $user = User::factory()->create();

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Restricoes->value,
        'content' => 'Hercules tem intolerância à lactose.',
        'embedding' => embeddingVectorAt(1),
    ]);

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Restricoes->value,
        'content' => 'Hercules não pode comer camarão.',
        'embedding' => embeddingVectorAt(2),
    ]);

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Objetivos->value,
        'content' => 'Hercules deseja emagrecer sem fazer uma dieta muito restritiva.',
        'embedding' => embeddingVectorAt(3),
    ]);

    $processedPrompt = '';

    NutritionistAgent::fake(function (string $prompt) use (&$processedPrompt) {
        $processedPrompt = $prompt;

        return 'Resposta considerando restrições e objetivo';
    });

    $response = (new NutritionistAgent($user))->prompt('Comi canjica com doce de leite e fui no banheiro duas vezes.');

    expect($response->text)->toBe('Resposta considerando restrições e objetivo')
        ->and($processedPrompt)->toContain('HARD SAFETY RESTRICTIONS')
        ->and($processedPrompt)->toContain('- [restricoes] Hercules tem intolerância à lactose.')
        ->and($processedPrompt)->toContain('- [restricoes] Hercules não pode comer camarão.')
        ->and($processedPrompt)->toContain('ACTIVE GOALS')
        ->and($processedPrompt)->toContain('- [objetivos] Hercules deseja emagrecer sem fazer uma dieta muito restritiva.')
        ->and($processedPrompt)->toContain('If the user\'s food, symptom, or requested advice may relate to a restriction, explicitly mention it and adapt the guidance.')
        ->and($processedPrompt)->toContain('Use goals to shape recommendations, tradeoffs, and next steps.')
        ->and($processedPrompt)->toContain('Do not mention unrelated memories just because they are present.');
});

it('deduplicates priority memories that also appear in semantic search', function () {
    $queryVector = embeddingVectorAt(0);

    Embeddings::fake(fn () => [$queryVector]);

    $user = User::factory()->create();

    UserMemory::create([
        'user_id' => $user->id,
        'category' => UserMemoryCategory::Restricoes->value,
        'content' => 'Usuária não pode consumir glúten',
        'embedding' => $queryVector,
    ]);

    $processedPrompt = '';

    NutritionistAgent::fake(function (string $prompt) use (&$processedPrompt) {
        $processedPrompt = $prompt;

        return 'Resposta sem duplicidade';
    });

    $response = (new NutritionistAgent($user))->prompt('Quero opções sem glúten');

    expect($response->text)->toBe('Resposta sem duplicidade')
        ->and(substr_count($processedPrompt, 'Usuária não pode consumir glúten'))->toBe(1);
});

it('can continue the same conversation after switching from OpenAI to Gemini', function () {
    NutritionistAgent::fake(function (string $prompt) {
        return str_contains($prompt, 'Gemini')
            ? 'Second response from Gemini'
            : 'First response from GPT';
    });

    $user = User::factory()->create();
    $user->profile()->create([
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'manter',
        'activity_level' => 'moderado',
        'preferred_ai_model' => AiModel::OpenAI->value,
    ]);
    $user->weightLogs()->create([
        'weight_kg' => 85.0,
        'logged_at' => now(),
    ]);

    $openAiAgent = new NutritionistAgent($user);

    expect($openAiAgent->provider())->toBe(AiModel::OpenAI->providerChain());

    $firstResponse = $openAiAgent
        ->forUser($user)
        ->prompt('Oi, quero começar por aqui com GPT.');

    expect($firstResponse->text)->toBe('First response from GPT');

    $user->profile()->update([
        'preferred_ai_model' => AiModel::Gemini->value,
    ]);

    $user->refresh();

    $geminiAgent = new NutritionistAgent($user);

    expect($geminiAgent->provider())->toBe(AiModel::Gemini->providerChain())
        ->and($geminiAgent->provider())->toHaveKey(Lab::Gemini->value, 'gemini-3.5-flash')
        ->and($geminiAgent->providerOptions(Lab::Gemini))->toBe([
            'thinkingConfig' => [
                'thinkingLevel' => 'medium',
            ],
        ])
        ->and($geminiAgent->providerOptions(Lab::OpenAI))->toBe([]);

    $secondResponse = $geminiAgent
        ->continue($firstResponse->conversationId, as: $user)
        ->prompt('Agora mudei para Gemini, pode continuar a conversa?');

    expect($secondResponse->text)->toBe('Second response from Gemini')
        ->and($secondResponse->conversationId)->toBe($firstResponse->conversationId);

    NutritionistAgent::assertPrompted('Oi, quero começar por aqui com GPT.');
    NutritionistAgent::assertPrompted('Agora mudei para Gemini, pode continuar a conversa?');
});

it('delegates preparation ingredient handling to the estimation flow', function () {
    $user = User::factory()->create();
    $user->profile()->create([
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'perder_peso',
        'activity_level' => 'moderado',
    ]);
    $user->weightLogs()->create([
        'weight_kg' => 85.0,
    ]);

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->toContain('`parse_meal_message` BEFORE `estimate_meal`')
        ->and($instructions)->toContain('returns `items_text`, `meal_type`, and `consumed_at`')
        ->and($instructions)->toContain('`estimate_meal` is the single source of truth for calories')
        ->and($instructions)->toContain('Register only when `estimate_meal` returns `registration_allowed=true`')
        ->and($instructions)->toContain('`estimate_meal` returns `items_for_registration_text`, `consumed_at`, `expected_items_count`, and `pending_items_count`')
        ->and($instructions)->toContain('If `register_meal` returns `registration_blocked`, do not say the meal was registered')
        ->and($instructions)->toContain('Use `context` in `estimate_meal` when there is preparation or indirect consumption')
        ->and($instructions)->toContain('The nutritional database for `estimate_meal` is configured in the application')
        ->and($instructions)->not->toContain('Arroz branco cozido: ~128 kcal/100g');
});

it('instructs the agent to give specific nutritional feedback instead of generic praise', function () {
    $user = User::factory()->create();

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->toContain('Avoid generic feedback like "good job" or "that was bad" without explaining why')
        ->and($instructions)->toContain('Always provide one concrete reading about the meal')
        ->and($instructions)->toContain('Ask one short, useful question rather than assuming too much')
        ->and($instructions)->toContain('plain text item lines returned by the tools');
});

it('adds follow-up context and clinical rails to the agent instructions', function () {
    $user = User::factory()->create();
    $chatMessageService = new ChatMessageService;
    $mealService = new MealService;

    try {
        Carbon::setTestNow(Carbon::parse('2026-06-06 20:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Ontem acabei pulando o jantar e fiquei com fome tarde.');
        $mealService->registerMeal($user, 'almoco', Carbon::parse('2026-06-06 12:30:00', config('app.timezone')));

        Carbon::setTestNow(Carbon::parse('2026-06-07 09:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Hoje quero retomar melhor.');

        $instructions = (string) (new NutritionistAgent($user))->instructions();

        expect($instructions)->toContain('FOLLOW-UP CONTEXT')
            ->and($instructions)->toContain('Previous user interaction before current message: 2026-06-06 20:00:00 | "Ontem acabei pulando o jantar e fiquei com fome tarde."')
            ->and($instructions)->toContain('Days since last user interaction before today: 1')
            ->and($instructions)->toContain('Absence context: none')
            ->and($instructions)->toContain('Yesterday (2026-06-06) user chat messages: 1')
            ->and($instructions)->toContain('Yesterday meal records: 1')
            ->and($instructions)->toContain('RELATIONSHIP CONTINUITY RAILS')
            ->and($instructions)->toContain('CLINICAL COACHING RAILS')
            ->and($instructions)->toContain('If the user reports symptoms after eating and a known restriction may relate to the food')
            ->and($instructions)->toContain('avoid confident low estimates')
            ->and($instructions)->toContain('If absence context is `none`, do not mention absence');
    } finally {
        Carbon::setTestNow();
    }
});

it('exposes multi-day gaps since the previous user interaction', function () {
    $user = User::factory()->create();
    $chatMessageService = new ChatMessageService;

    try {
        Carbon::setTestNow(Carbon::parse('2026-06-03 20:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Passei uns dias sem registrar direito.');

        Carbon::setTestNow(Carbon::parse('2026-06-07 09:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Voltei hoje para organizar minha alimentação.');

        $instructions = (string) (new NutritionistAgent($user))->instructions();

        expect($instructions)->toContain('Previous user interaction before current message: 2026-06-03 20:00:00 | "Passei uns dias sem registrar direito."')
            ->and($instructions)->toContain('Days since last user interaction before today: 4')
            ->and($instructions)->toContain('Absence context: User has been away for 4 days.')
            ->and($instructions)->toContain('gently acknowledge it once in PT-BR with a warm check-in');
    } finally {
        Carbon::setTestNow();
    }
});

it('caches daily absence context while keeping the previous message fresh', function () {
    $user = User::factory()->create();
    $chatMessageService = new ChatMessageService;

    try {
        Carbon::setTestNow(Carbon::parse('2026-06-03 20:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Último contato antes de sumir.');

        Carbon::setTestNow(Carbon::parse('2026-06-07 09:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Voltei hoje pela manhã.');

        $firstInstructions = (string) (new NutritionistAgent($user))->instructions();
        $cacheKey = "nutritionist-agent:daily-follow-up-context:user:{$user->id}:2026-06-07";

        expect($firstInstructions)->toContain('Previous user interaction before current message: 2026-06-03 20:00:00 | "Último contato antes de sumir."')
            ->and($firstInstructions)->toContain('Absence context: User has been away for 4 days.')
            ->and($firstInstructions)->toContain('Yesterday (2026-06-06) user chat messages: 0')
            ->and(Cache::has($cacheKey))->toBeTrue();

        Carbon::setTestNow(Carbon::parse('2026-06-06 18:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Mensagem inserida depois do cache.');

        Carbon::setTestNow(Carbon::parse('2026-06-07 10:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Segunda mensagem de hoje.');

        $secondInstructions = (string) (new NutritionistAgent($user))->instructions();

        expect($secondInstructions)->toContain('Previous user interaction before current message: 2026-06-07 09:00:00 | "Voltei hoje pela manhã."')
            ->and($secondInstructions)->toContain('Absence context: User has been away for 4 days.')
            ->and($secondInstructions)->toContain('Yesterday (2026-06-06) user chat messages: 0');
    } finally {
        Carbon::setTestNow();
    }
});

it('humanizes longer absence contexts for the agent', function (string $previousInteractionDate, string $expectedAbsenceContext) {
    $user = User::factory()->create();
    $chatMessageService = new ChatMessageService;

    try {
        Carbon::setTestNow(Carbon::parse($previousInteractionDate, config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Estou voltando depois de um tempo fora.');

        Carbon::setTestNow(Carbon::parse('2026-06-07 09:00:00', config('app.timezone')));
        $chatMessageService->storeUserMessage($user, 'Quero retomar meu acompanhamento.');

        $instructions = (string) (new NutritionistAgent($user))->instructions();

        expect($instructions)->toContain("Absence context: {$expectedAbsenceContext}");
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'one week away' => ['2026-05-31 20:00:00', 'User has been away for about 1 week (7 days).'],
    'two months away' => ['2026-04-04 20:00:00', 'User has been away for about 2 months (64 days).'],
    'one year away' => ['2025-06-07 20:00:00', 'User has been away for about 1 year (365 days).'],
]);

it('injects the previous conversation cycle summary into the prompt', function () {
    $user = User::factory()->create();

    $user->conversationSummaries()->create([
        'summary_type' => ConversationSummaryType::ConversationCycle,
        'trigger_type' => ConversationSummaryTriggerType::Weekly,
        'period_start' => Carbon::parse('2026-06-01 00:00:00'),
        'period_end' => Carbon::parse('2026-06-07 23:59:59'),
        'summary' => 'The user kept a steady lunch routine and struggled with late snacks.',
        'stats' => [],
    ]);

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->toContain('Previous conversation cycle summary')
        ->and($instructions)->toContain('### Cycle from 2026-06-01 to 2026-06-07')
        ->and($instructions)->toContain('The user kept a steady lunch routine and struggled with late snacks.')
        ->and($instructions)->not->toContain('Previous months summary');
});

it('guides meal registration with calories effectively consumed', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    expect((string) $tool->description())->toContain('plain text item lines')
        ->and((string) $tool->description())->toContain('pass consumed_at, expected_items_count, and pending_items_count unchanged')
        ->and((string) $tool->description())->toContain('registration_blocked');
});

/**
 * @return array<int, float>
 */
function embeddingVectorAt(int $index): array
{
    $vector = array_fill(0, 1536, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}
