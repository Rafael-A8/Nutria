<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Tools\RegisterMealTool;
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
        'logged_at' => now(),
    ]);

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->toContain('`estimate_meal` é a fonte de verdade para calorias')
        ->and($instructions)->toContain('Use `context` em `estimate_meal` quando houver preparo ou consumo indireto')
        ->and($instructions)->toContain('A base nutricional do `estimate_meal` fica na configuração da aplicação')
        ->and($instructions)->not->toContain('Arroz branco cozido: ~128 kcal/100g');
});

it('instructs the agent to give specific nutritional feedback instead of generic praise', function () {
    $user = User::factory()->create();

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->toContain('Evite feedback genérico como "muito bem" ou "foi ruim" sem explicar o motivo')
        ->and($instructions)->toContain('Sempre traga 1 leitura concreta da refeição')
        ->and($instructions)->toContain('1 pergunta curta e útil em vez de assumir demais');
});

it('guides meal registration with calories effectively consumed', function () {
    $user = User::factory()->create();
    $tool = new RegisterMealTool($user);

    expect((string) $tool->description())->toContain('calorias efetivamente ingeridas')
        ->and((string) $tool->description())->toContain('fração absorvida/consumida');
});

it('instructs the agent to estimate meals before registering them', function () {
    $user = User::factory()->create();

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->toContain('use `estimate_meal` ANTES de `register_meal`')
        ->and($instructions)->toContain('status = clarification_required')
        ->and($instructions)->toContain('use exatamente `items_for_registration`')
        ->and($instructions)->toContain('`user_facing_summary` como espinha da explicação ao usuário')
        ->and($instructions)->toContain('`assistant_response_guide` para orientar seu próximo passo');
});
