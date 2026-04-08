<?php

use App\Models\Profile;
use App\Models\User;

test('guests cannot access ai model settings', function () {
    $this->get(route('ai-model.edit'))->assertRedirect(route('login'));
});

test('authenticated users can view ai model settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('ai-model.edit'))
        ->assertOk();
});

test('it returns default model when user has no profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('ai-model.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/AiModel')
        ->where('currentModel', 'gemini-2.0-flash-lite')
    );
});

test('it updates the preferred ai model to gemini', function () {
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
        ->patch(route('ai-model.update'), [
            'preferred_ai_model' => 'gemini-2.0-flash-lite',
        ])
        ->assertRedirect(route('ai-model.edit'));

    expect($user->profile->fresh()->preferred_ai_model)->toBe('gemini-2.0-flash-lite');
});

test('it creates profile when updating model if no profile exists', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('ai-model.update'), [
            'preferred_ai_model' => 'gemini-2.0-flash-lite',
        ])
        ->assertRedirect(route('ai-model.edit'));

    $user->refresh();

    expect($user->profile)->not->toBeNull();
    expect($user->profile->preferred_ai_model)->toBe('gemini-2.0-flash-lite');
});

test('it rejects invalid model values', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('ai-model.update'), [
            'preferred_ai_model' => 'invalid-model',
        ])
        ->assertSessionHasErrors('preferred_ai_model');
});
