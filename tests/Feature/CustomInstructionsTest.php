<?php

use App\Ai\Agents\NutritionistAgent;
use App\Models\Profile;
use App\Models\User;

test('it returns empty custom instructions when user has no profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai-model.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/AiModel')
            ->where('customInstructions', '')
        );
});

test('it returns existing custom instructions', function () {
    $user = User::factory()->create();
    Profile::create([
        'user_id' => $user->id,
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'manter',
        'activity_level' => 'moderado',
        'custom_instructions' => 'Sou vegetariano e intolerante à lactose.',
    ]);

    $this->actingAs($user)
        ->get(route('ai-model.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/AiModel')
            ->where('customInstructions', 'Sou vegetariano e intolerante à lactose.')
        );
});

test('it updates custom instructions for user with profile', function () {
    $user = User::factory()->create();
    Profile::create([
        'user_id' => $user->id,
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'manter',
        'activity_level' => 'moderado',
    ]);

    $this->actingAs($user)
        ->patch(route('ai-model.update-instructions'), [
            'custom_instructions' => 'Sou diabético tipo 2.',
        ])
        ->assertRedirect(route('ai-model.edit'));

    expect($user->profile->fresh()->custom_instructions)->toBe('Sou diabético tipo 2.');
});

test('it creates profile when updating instructions if no profile exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('ai-model.update-instructions'), [
            'custom_instructions' => 'Prefiro respostas curtas.',
        ])
        ->assertRedirect(route('ai-model.edit'));

    $user->refresh();

    expect($user->profile)->not->toBeNull();
    expect($user->profile->custom_instructions)->toBe('Prefiro respostas curtas.');
});

test('it allows nullable custom instructions', function () {
    $user = User::factory()->create();
    Profile::create([
        'user_id' => $user->id,
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'manter',
        'activity_level' => 'moderado',
        'custom_instructions' => 'Instruções antigas.',
    ]);

    $this->actingAs($user)
        ->patch(route('ai-model.update-instructions'), [
            'custom_instructions' => null,
        ])
        ->assertRedirect(route('ai-model.edit'));

    expect($user->profile->fresh()->custom_instructions)->toBeNull();
});

test('it rejects custom instructions longer than 1000 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('ai-model.update-instructions'), [
            'custom_instructions' => str_repeat('a', 1001),
        ])
        ->assertSessionHasErrors('custom_instructions');
});

test('guests cannot update custom instructions', function () {
    $this->patch(route('ai-model.update-instructions'), [
        'custom_instructions' => 'Teste',
    ])->assertRedirect(route('login'));
});

test('agent instructions include custom instructions when set', function () {
    $user = User::factory()->create();
    $user->profile()->create([
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'manter',
        'activity_level' => 'moderado',
        'custom_instructions' => 'Sou vegetariano. Me chame de Rafa.',
    ]);

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)
        ->toContain('INSTRUÇÕES PERSONALIZADAS DO USUÁRIO (respeite sempre):')
        ->toContain('Sou vegetariano. Me chame de Rafa.');
});

test('agent instructions do not include custom instructions section when empty', function () {
    $user = User::factory()->create();
    $user->profile()->create([
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 175,
        'goal' => 'manter',
        'activity_level' => 'moderado',
        'custom_instructions' => null,
    ]);

    $instructions = (string) (new NutritionistAgent($user))->instructions();

    expect($instructions)->not->toContain('INSTRUÇÕES PERSONALIZADAS DO USUÁRIO');
});
