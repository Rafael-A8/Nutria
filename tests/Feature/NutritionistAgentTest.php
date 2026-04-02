<?php

use App\Ai\Agents\NutritionistAgent;
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
