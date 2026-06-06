<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Tools\SaveMemoryTool;
use App\Enums\ConversationSummaryTriggerType;
use App\Enums\ConversationSummaryType;
use App\Enums\UserMemoryCategory;
use App\Models\User;
use App\Models\UserMemory;
use App\Services\ChatMessageService;
use App\Services\MealService;
use App\Services\SummaryService;
use App\Services\WeightLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

it('validates the weekly conversation summary flow before production', function () {
    expect(config('app.timezone'))->toBe('America/Sao_Paulo')
        ->and(now()->timezone(config('app.timezone'))->timezoneName)->toBe('America/Sao_Paulo')
        ->and(Schema::hasTable('summaries'))->toBeFalse()
        ->and(Schema::hasTable('user_conversation_summaries'))->toBeTrue();

    Embeddings::fake(fn () => [weeklyConversationSummaryEmbeddingVectorAt(0)]);

    AnonymousAgent::fake([
        'The previous cycle shows a mostly consistent week with a birthday meal, drinks with friends, and a heavier Sunday lunch with family. The user still tracked most days, which is a useful sign of continuity.',
    ]);

    $user = User::factory()->create([
        'name' => 'Teste Summary Semanal',
    ]);

    $user->profile()->create([
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'perder_peso',
        'activity_level' => 'moderado',
    ]);

    $mealService = new MealService;
    $weightLogService = new WeightLogService;
    $chatMessageService = new ChatMessageService;
    $summaryService = new SummaryService($mealService, $weightLogService, $chatMessageService);

    $at = fn (string $date): Carbon => Carbon::parse($date, config('app.timezone'));

    $storeUserMessage = function (string $date, string $content) use ($at, $chatMessageService, $user): void {
        Carbon::setTestNow($at($date));

        $chatMessageService->storeUserMessage($user, $content);
    };

    $registerMeal = function (string $type, string $date, array $items) use ($at, $mealService, $user): void {
        $meal = $mealService->registerMeal($user, $type, $at($date));

        foreach ($items as [$description, $quantityGrams, $calories]) {
            $mealService->addItem($meal, $description, $quantityGrams, $calories);
        }
    };

    try {
        $weightLogService->log($user, 82.0, $at('2026-06-01 07:30:00'));

        $storeUserMessage('2026-06-01 08:00:00', 'Tenho alergia a frutos do mar.');
        $memoryResult = (new SaveMemoryTool($user))->handle(new Request([
            'content' => 'Usuário tem alergia a frutos do mar.',
            'category' => UserMemoryCategory::Restricoes->value,
        ]));

        expect($memoryResult)->toBe('memory_saved');

        $registerMeal('cafe_da_manha', '2026-06-01 08:15:00', [
            ['ovos mexidos', 120, 180],
            ['banana', 90, 80],
        ]);
        $registerMeal('almoco', '2026-06-01 12:30:00', [
            ['arroz integral', 150, 180],
            ['frango grelhado', 160, 260],
        ]);
        $storeUserMessage('2026-06-01 20:00:00', 'Fechei o dia com uma janta leve.');
        $registerMeal('jantar', '2026-06-01 20:10:00', [
            ['sopa de legumes', 300, 220],
        ]);

        $storeUserMessage('2026-06-02 12:00:00', 'Hoje foi um dia normal, consegui seguir bem.');
        $registerMeal('almoco', '2026-06-02 12:30:00', [
            ['feijao', 120, 110],
            ['patinho moido', 150, 240],
        ]);
        $registerMeal('jantar', '2026-06-02 19:40:00', [
            ['omelete', 180, 280],
        ]);

        $registerMeal('cafe_da_manha', '2026-06-03 08:10:00', [
            ['iogurte natural', 170, 120],
            ['granola', 40, 150],
        ]);
        $registerMeal('almoco', '2026-06-03 12:20:00', [
            ['macarrao integral', 180, 260],
            ['carne magra', 130, 230],
        ]);
        $storeUserMessage('2026-06-03 21:30:00', 'Hoje fui em um aniversário e acabei comendo mais do que planejava.');
        $registerMeal('jantar', '2026-06-03 21:35:00', [
            ['salgadinhos de festa', 160, 520],
            ['bolo de aniversario', 110, 380],
        ]);

        $storeUserMessage('2026-06-05 08:20:00', 'Hoje tentei voltar ao ritmo no café e almoço.');
        $registerMeal('cafe_da_manha', '2026-06-05 08:25:00', [
            ['pao integral', 60, 150],
            ['queijo branco', 50, 120],
        ]);
        $registerMeal('almoco', '2026-06-05 12:40:00', [
            ['salada completa', 250, 220],
            ['frango grelhado', 160, 260],
        ]);
        $storeUserMessage('2026-06-05 22:30:00', 'Sai para beber com amigos depois do serviço.');
        $registerMeal('outro', '2026-06-05 22:35:00', [
            ['cerveja', 700, 300],
            ['batata frita', 180, 560],
        ]);

        $storeUserMessage('2026-06-06 19:00:00', 'Sábado normal, sem grandes eventos.');
        $registerMeal('almoco', '2026-06-06 12:30:00', [
            ['arroz', 140, 180],
            ['feijao', 120, 110],
            ['frango', 150, 250],
        ]);
        $registerMeal('jantar', '2026-06-06 19:30:00', [
            ['wrap de frango', 220, 420],
        ]);

        $registerMeal('cafe_da_manha', '2026-06-07 08:30:00', [
            ['tapioca', 100, 240],
            ['cafe com leite', 200, 120],
        ]);
        $storeUserMessage('2026-06-07 14:00:00', 'Minha sogra veio em casa e acabei comendo além da conta no almoço.');
        $registerMeal('almoco', '2026-06-07 14:10:00', [
            ['lasanha', 300, 760],
            ['pudim', 120, 360],
        ]);

        expect(UserMemory::query()
            ->whereBelongsTo($user)
            ->where('category', UserMemoryCategory::Restricoes->value)
            ->where('content', 'Usuário tem alergia a frutos do mar.')
            ->exists())->toBeTrue()
            ->and($user->chatMessages()->count())->toBeGreaterThanOrEqual(8);

        Carbon::setTestNow($at('2026-06-08 09:00:00'));

        $summary = $summaryService->generateConversationCycleSummaryIfNeeded($user);

        expect($summary)
            ->not->toBeNull()
            ->summary_type->toBe(ConversationSummaryType::ConversationCycle)
            ->trigger_type->toBe(ConversationSummaryTriggerType::Weekly)
            ->period_start->toDateTimeString()->toBe('2026-06-01 00:00:00')
            ->period_end->toDateTimeString()->toBe('2026-06-07 23:59:59')
            ->summary->toContain('birthday meal')
            ->stats->toBeArray();

        expect($summary->stats['meals']['days_tracked'])->toBe(6)
            ->and($summary->stats['meals']['total_meals'])->toBe(15)
            ->and($summary->stats['meals']['total_calories'])->toBeGreaterThan(0)
            ->and($summary->message_count)->toBeNull()
            ->and($summary->token_count)->toBeNull()
            ->and($user->conversationSummaries()->count())->toBe(1);

        AnonymousAgent::assertPrompted(fn ($prompt): bool => str_contains(
            $prompt->prompt,
            'Conversation cycle from 2026-06-01 to 2026-06-07:'
        ));

        $duplicateSummary = $summaryService->generateConversationCycleSummaryIfNeeded($user);

        expect($duplicateSummary)->toBeNull()
            ->and($user->conversationSummaries()->count())->toBe(1);

        $instructions = (string) (new NutritionistAgent($user))->instructions();

        expect($instructions)->toContain('Name: Teste Summary Semanal')
            ->and($instructions)->toContain('Goal: perder_peso')
            ->and($instructions)->toContain('Activity: moderado')
            ->and($instructions)->toContain('Previous conversation cycle summary')
            ->and($instructions)->toContain('birthday meal')
            ->and($instructions)->toContain('drinks with friends')
            ->and($instructions)->toContain('heavier Sunday lunch with family');

        $processedPrompts = [];

        NutritionistAgent::fake(function (string $prompt) use (&$processedPrompts): string {
            $processedPrompts[] = $prompt;

            if (str_contains($prompt, 'risoto de camarão')) {
                return 'Melhor evitar camarão e frutos do mar por causa da sua alergia. Vamos pensar em um risoto seguro, sem frutos do mar.';
            }

            if (str_contains($prompt, 'por onde começo')) {
                return 'Comece por uma retomada leve: café da manhã simples, almoço planejado e hidratação. Sem culpa pelos eventos sociais da semana passada.';
            }

            return 'Foi uma semana mais bagunçada, com aniversário, saída com amigos e almoço em família, mas você manteve registros em boa parte dos dias.';
        });

        $firstResponse = (new NutritionistAgent($user))
            ->forUser($user)
            ->prompt('Essa semana passada foi meio bagunçada, né?');

        $secondResponse = (new NutritionistAgent($user))
            ->continue($firstResponse->conversationId, as: $user)
            ->prompt('Quero tentar voltar melhor essa semana, por onde começo?');

        $allergyResponse = (new NutritionistAgent($user))
            ->forUser($user)
            ->prompt('Hoje estou pensando em comer risoto de camarão, pode ser?');

        expect($firstResponse->text)->toContain('aniversário')
            ->and($firstResponse->text)->toContain('amigos')
            ->and($secondResponse->text)->toContain('retomada leve')
            ->and($secondResponse->text)->toContain('Sem culpa')
            ->and($allergyResponse->text)->toContain('evitar camarão')
            ->and($allergyResponse->text)->toContain('alergia')
            ->and($processedPrompts[0])->toContain('USER MEMORIES')
            ->and($processedPrompts[0])->toContain('- [restricoes] Usuário tem alergia a frutos do mar.')
            ->and($processedPrompts[2])->toContain('USER MEMORIES')
            ->and($processedPrompts[2])->toContain('- [restricoes] Usuário tem alergia a frutos do mar.');
    } finally {
        Carbon::setTestNow();
    }
});

/**
 * @return array<int, float>
 */
function weeklyConversationSummaryEmbeddingVectorAt(int $index): array
{
    $vector = array_fill(0, 1536, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}
