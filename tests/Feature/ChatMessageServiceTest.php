<?php

use App\Models\User;
use App\Services\ChatMessageService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->service = new ChatMessageService;
});

it('stores a user text message', function () {
    $message = $this->service->storeUserMessage($this->user, 'Oi, comi arroz');

    expect($message)
        ->user_id->toBe($this->user->id)
        ->role->toBe('user')
        ->content->toBe('Oi, comi arroz')
        ->audio_path->toBeNull();

    $this->assertDatabaseHas('chat_messages', [
        'id' => $message->id,
        'role' => 'user',
        'content' => 'Oi, comi arroz',
    ]);
});

it('stores a user message with audio path', function () {
    $message = $this->service->storeUserMessage($this->user, 'texto transcrito', 'audio/1/abc.webm');

    expect($message)
        ->role->toBe('user')
        ->audio_path->toBe('audio/1/abc.webm');
});

it('stores an assistant message', function () {
    $message = $this->service->storeAssistantMessage($this->user, 'Registrei sua refeição!');

    expect($message)
        ->user_id->toBe($this->user->id)
        ->role->toBe('assistant')
        ->content->toBe('Registrei sua refeição!')
        ->audio_path->toBeNull();
});

it('gets chat history in chronological order', function () {
    $this->service->storeUserMessage($this->user, 'primeira');
    $this->service->storeAssistantMessage($this->user, 'resposta');
    $this->service->storeUserMessage($this->user, 'segunda');

    $history = $this->service->getHistory($this->user);

    expect($history)->toHaveCount(3);
    expect($history->first()->content)->toBe('primeira');
    expect($history->last()->content)->toBe('segunda');
});

it('limits chat history', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->service->storeUserMessage($this->user, "msg {$i}");
    }

    $history = $this->service->getHistory($this->user, limit: 3);

    expect($history)->toHaveCount(3);
    expect($history->first()->content)->toBe('msg 3');
    expect($history->last()->content)->toBe('msg 5');
});

it('does not include other users messages', function () {
    $otherUser = User::factory()->create();
    $this->service->storeUserMessage($otherUser, 'outra pessoa');
    $this->service->storeUserMessage($this->user, 'minha mensagem');

    $history = $this->service->getHistory($this->user);

    expect($history)->toHaveCount(1);
    expect($history->first()->content)->toBe('minha mensagem');
});

it('stores a user message with image paths', function () {
    $imagePaths = ['images/1/photo1.jpg', 'images/1/photo2.png'];
    $message = $this->service->storeUserMessage($this->user, 'Almocei isso', imagePaths: $imagePaths);

    expect($message)
        ->role->toBe('user')
        ->content->toBe('Almocei isso')
        ->image_paths->toBe($imagePaths);

    $this->assertDatabaseHas('chat_messages', [
        'id' => $message->id,
        'content' => 'Almocei isso',
    ]);

    $fresh = $message->fresh();
    expect($fresh->image_paths)->toBe($imagePaths);
});

it('stores a user message with images and no text', function () {
    $imagePaths = ['images/1/photo1.jpg'];
    $message = $this->service->storeUserMessage($this->user, 'Enviou 1 imagem', imagePaths: $imagePaths);

    expect($message)
        ->image_paths->toHaveCount(1)
        ->audio_path->toBeNull();
});
