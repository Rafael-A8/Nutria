<?php

use App\Ai\Agents\NutritionistAgent;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Transcription;

it('transcribes audio and sends to agent', function () {
    Transcription::fake(['Comi arroz e feijão no almoço']);
    NutritionistAgent::fake(['Registrei sua refeição!']);
    Storage::fake('local');

    $user = User::factory()->create();
    $audio = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

    $response = $this->actingAs($user)
        ->postJson(route('chat.send-audio'), ['audio' => $audio]);

    $response->assertSuccessful()
        ->assertJsonStructure(['reply', 'conversationId', 'transcription'])
        ->assertJson(['transcription' => 'Comi arroz e feijão no almoço']);

    Transcription::assertGenerated(fn ($prompt) => $prompt->language === 'pt');
    NutritionistAgent::assertPrompted('Comi arroz e feijão no almoço');

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Comi arroz e feijão no almoço',
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $user->id,
        'role' => 'assistant',
        'content' => 'Registrei sua refeição!',
    ]);
});

it('stores audio file on disk', function () {
    Transcription::fake(['Texto transcrito']);
    NutritionistAgent::fake(['Ok!']);
    Storage::fake('local');

    $user = User::factory()->create();
    $audio = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

    $this->actingAs($user)
        ->postJson(route('chat.send-audio'), ['audio' => $audio])
        ->assertSuccessful();

    $message = ChatMessage::where('role', 'user')->first();
    expect($message->audio_path)->toStartWith("audio/{$user->id}/");

    Storage::disk('local')->assertExists($message->audio_path);
});

it('requires authentication', function () {
    $audio = UploadedFile::fake()->create('audio.mp3', 100, 'audio/mpeg');

    $this->postJson(route('chat.send-audio'), ['audio' => $audio])
        ->assertUnauthorized();
});

it('rejects invalid file types', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->postJson(route('chat.send-audio'), ['audio' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('audio');
});

it('rejects files exceeding max size', function () {
    $user = User::factory()->create();
    $audio = UploadedFile::fake()->create('audio.mp3', 11000, 'audio/mpeg');

    $this->actingAs($user)
        ->postJson(route('chat.send-audio'), ['audio' => $audio])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('audio');
});

it('sends text message and stores chat messages', function () {
    NutritionistAgent::fake(['Olá! Como posso ajudar?']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'Oi']);

    $response->assertSuccessful()
        ->assertJson(['reply' => 'Olá! Como posso ajudar?']);

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Oi',
        'audio_path' => null,
    ]);

    $this->assertDatabaseHas('chat_messages', [
        'user_id' => $user->id,
        'role' => 'assistant',
        'content' => 'Olá! Como posso ajudar?',
    ]);
});
