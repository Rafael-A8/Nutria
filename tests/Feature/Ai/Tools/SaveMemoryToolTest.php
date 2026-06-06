<?php

use App\Ai\Tools\SaveMemoryTool;
use App\Enums\UserMemoryCategory;
use App\Models\User;
use App\Models\UserMemory;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

test('SaveMemoryTool can save user memory with embedding without array to string conversion error', function () {
    // Mock embeddings to return a fake vector
    Embeddings::fake();

    $user = User::factory()->create();
    $tool = new SaveMemoryTool($user);

    $request = new Request([
        'content' => 'Usuária se identifica como feminina e prefere ser chamada de Soraia.',
        'category' => UserMemoryCategory::Preferencias->value,
    ]);

    $result = $tool->handle($request);

    expect($result)->toBe('memory_saved');

    $memory = UserMemory::where('user_id', $user->id)->first();
    expect($memory)->not->toBeNull();
    expect($memory->content)->toBe('Usuária se identifica como feminina e prefere ser chamada de Soraia.');
    expect($memory->category)->toBe(UserMemoryCategory::Preferencias->value);
    expect($memory->embedding)->toBeArray();
});

test('SaveMemoryTool does not save duplicate memories', function () {
    Embeddings::fake();

    $user = User::factory()->create();
    $tool = new SaveMemoryTool($user);

    $request = new Request([
        'content' => 'Usuária gosta de comer tarde da noite.',
        'category' => UserMemoryCategory::Comportamento->value,
    ]);

    $tool->handle($request);
    $result = $tool->handle($request);

    expect($result)->toBe('memory_already_exists');
    expect(UserMemory::where('user_id', $user->id)->count())->toBe(1);
});

test('SaveMemoryTool rejects invalid memory category', function () {
    Embeddings::fake();

    $user = User::factory()->create();
    $tool = new SaveMemoryTool($user);

    $request = new Request([
        'content' => 'Usuária evita leite por intolerância.',
        'category' => 'food_preferences',
    ]);

    $result = $tool->handle($request);

    expect($result)->toBe('invalid_memory_category');
    expect(UserMemory::where('user_id', $user->id)->count())->toBe(0);
    Embeddings::assertNothingGenerated();
});

test('SaveMemoryTool schema restricts category to the memory category enum', function () {
    $user = User::factory()->create();
    $tool = new SaveMemoryTool($user);

    $schema = $tool->schema(new JsonSchemaTypeFactory);
    $categorySchema = $schema['category']->toArray();

    expect($categorySchema['enum'])->toBe(UserMemoryCategory::values())
        ->and($categorySchema['description'])->toContain('Must be exactly one of: restricoes, preferencias, comportamento, objetivos.');
});
