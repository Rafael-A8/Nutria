<?php

use App\Ai\Agents\NutritionistAgent;
use App\Ai\Tools\UpdateProfileTool;
use App\Models\User;
use Laravel\Ai\Tools\Request;

it('creates a profile when user has none', function (): void {
    $user = User::factory()->create();

    $tool = new UpdateProfileTool($user);
    $request = new Request([
        'gender' => 'masculino',
        'birth_date' => '1990-05-15',
        'height_cm' => 175,
        'goal' => 'perder_peso',
        'activity_level' => 'moderado',
        'weight_kg' => 85.5,
    ]);

    $result = $tool->handle($request);

    expect($result)->toContain('Perfil atualizado');

    $user->refresh();
    expect($user->profile)->not->toBeNull()
        ->and($user->profile->gender)->toBe('masculino')
        ->and($user->profile->height_cm)->toBe(175)
        ->and($user->profile->goal)->toBe('perder_peso')
        ->and($user->profile->activity_level)->toBe('moderado');

    expect($user->weightLogs()->count())->toBe(1)
        ->and((float) $user->weightLogs()->first()->weight_kg)->toBe(85.5);
});

it('updates existing profile fields without overwriting others', function (): void {
    $user = User::factory()->create();
    $user->profile()->create([
        'gender' => 'masculino',
        'birth_date' => '1990-01-01',
        'height_cm' => 170,
        'goal' => 'manter_peso',
        'activity_level' => 'sedentario',
    ]);

    $tool = new UpdateProfileTool($user);
    $request = new Request([
        'goal' => 'perder_peso',
        'activity_level' => 'moderado',
    ]);

    $tool->handle($request);

    $user->refresh();
    expect($user->profile->goal)->toBe('perder_peso')
        ->and($user->profile->activity_level)->toBe('moderado')
        ->and($user->profile->height_cm)->toBe(170)
        ->and($user->profile->gender)->toBe('masculino');
});

it('does not register weight when weight_kg is not provided', function (): void {
    $user = User::factory()->create();

    $tool = new UpdateProfileTool($user);
    $request = new Request([
        'gender' => 'feminino',
        'birth_date' => '1995-03-20',
        'height_cm' => 160,
        'goal' => 'manter_peso',
        'activity_level' => 'leve',
    ]);

    $tool->handle($request);

    expect($user->weightLogs()->count())->toBe(0);
});

it('includes profile collection instructions when profile is incomplete', function (): void {
    $user = User::factory()->create();

    $agent = new NutritionistAgent($user);
    $instructions = (string) $agent->instructions();

    expect($instructions)->toContain('COLETA DE PERFIL')
        ->and($instructions)->toContain('update_profile');
});

it('does not include profile collection when profile is complete', function (): void {
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

    $agent = new NutritionistAgent($user);
    $instructions = (string) $agent->instructions();

    expect($instructions)->not->toContain('COLETA DE PERFIL');
});
