<?php

use App\Ai\Tools\SaveMemoryTool;
use App\Models\User;
use App\Models\UserMemory;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

test('SaveMemoryTool can save user memory with embedding without array to string conversion error', function () {
    // Mock embeddings to return a fake vector
    Embeddings::fake();

    $user = User::factory()->create();
    $tool = new SaveMemoryTool($user);

    $request = new Request([
        'content' => 'User identifies as female and prefers to be called Soraia.',
        'category' => 'preferencias',
    ]);

    $result = $tool->handle($request);

    expect($result)->toBe('memory_saved');

    $memory = UserMemory::where('user_id', $user->id)->first();
    expect($memory)->not->toBeNull();
    expect($memory->content)->toBe('User identifies as female and prefers to be called Soraia.');
    expect($memory->category)->toBe('preferencias');
    expect($memory->embedding)->toBeArray();
});

test('SaveMemoryTool does not save duplicate memories', function () {
    Embeddings::fake();

    $user = User::factory()->create();
    $tool = new SaveMemoryTool($user);

    $request = new Request([
        'content' => 'User loves eating late at night.',
        'category' => 'comportamento',
    ]);

    $tool->handle($request);
    $result = $tool->handle($request);

    expect($result)->toBe('memory_already_exists');
    expect(UserMemory::where('user_id', $user->id)->count())->toBe(1);
});

