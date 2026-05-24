<?php

use App\Ai\Tools\GetSimilarItemsTool;
use App\Models\User;
use App\Services\MealService;
use Laravel\Ai\Tools\Request;

it('searches similar items from plain text descriptions', function () {
    $user = User::factory()->create();
    $mealService = Mockery::mock(MealService::class);
    $mealService->shouldReceive('findSimilarItems')
        ->once()
        ->with($user, 'arroz')
        ->andReturn(collect([(object) ['description' => 'arroz', 'quantity_grams' => 130, 'calories' => 166]]));
    $mealService->shouldReceive('findSimilarItems')
        ->once()
        ->with($user, 'feijao')
        ->andReturn(collect());

    $tool = new GetSimilarItemsTool($user, $mealService);

    $result = $tool->handle(new Request([
        'descriptions' => "arroz\nfeijao",
    ]));

    expect($result)->toContain('Itens similares encontrados')
        ->and($result)->toContain('arroz');
});
