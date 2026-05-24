<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Tools\RegisterMealTool;
use App\Enums\AiModel;
use App\Models\User;

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

it('guides meal registration with calories effectively consumed', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    expect((string) $tool->description())->toContain('plain text item lines')
        ->and((string) $tool->description())->toContain('calories actually consumed');
});
