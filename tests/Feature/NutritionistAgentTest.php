<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Tools\RegisterMealTool;
use App\Enums\AiModel;
use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use App\Enums\UserMemoryCategory;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

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
        ->and($processedPrompt)->toContain('- [comportamento] Usuária tem dificuldade com compulsão alimentar')
        ->and($processedPrompt)->toContain('Use these memories naturally when relevant.')
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
        ->and($processedPrompt)->toContain('- [restricoes] Usuária não pode consumir lactose')
        ->and($processedPrompt)->toContain('- [objetivos] Usuária quer manter consistência semanal')
        ->and($processedPrompt)->not->toContain('Usuária gosta de refeições salgadas');
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

    expect($geminiAgent->provider())->toBe(AiModel::Gemini->providerChain());

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
        ->and($instructions)->toContain('returns `items_text` as plain text lines')
        ->and($instructions)->toContain('`estimate_meal` is the single source of truth for calories')
        ->and($instructions)->toContain('`estimate_meal` returns `items_for_registration_text`')
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
        ->and((string) $tool->description())->toContain('calories actually consumed');
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
